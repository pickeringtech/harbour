<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Integrations\Orca;

use JsonException;
use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Support\AtomicFile;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final readonly class OrcaIntegration
{
    public const CONFIGURATION_PATH = 'orca.yaml';

    public const MINIMUM_VERSION = '1.4.184';

    public const MAXIMUM_VERSION = '1.5.0';

    public const BLOCKING_ARCHIVE_CAPABILITY = 'worktree.archive-failure-blocking.v1';

    public const SETUP = 'composer install --no-interaction && composer workspace:setup';

    public const ARCHIVE = 'composer workspace:teardown -- --force';

    public const MANUAL_MERGE = <<<'YAML'
    scripts:
      setup: composer install --no-interaction && composer workspace:setup
      archive: composer workspace:teardown -- --force
    YAML;

    private const MARKER = '# Harbour Orca lifecycle integration.';

    private const CONFIGURATION = <<<'YAML'
    # Harbour Orca lifecycle integration.
    # Setup restores dependencies before Harbour creates the isolated environment.
    # Archive must succeed while the checkout and .harbour.json still exist.
    scripts:
      setup: composer install --no-interaction && composer workspace:setup
      archive: composer workspace:teardown -- --force
    YAML;

    private const MAXIMUM_CONFIGURATION_BYTES = 262144;

    public function __construct(
        private string $workspacePath,
        private CommandRunner $runner,
        private AtomicFile $files = new AtomicFile,
        private ?string $binary = null,
    ) {}

    public function prepare(bool $reconfigure = false): OrcaInstallation
    {
        $this->assertSupportedTool();
        $path = $this->configurationPath();
        $this->assertSafePath($path);
        $exists = is_file($path);
        $existing = $exists ? @file_get_contents($path) : '';
        if ($existing === false) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'Unable to read Orca project configuration.');
        }

        $configuration = $this->configuration($existing, $exists);
        $classification = $this->classify($configuration, $existing, $exists);
        if ($classification === 'equivalent' || $classification === 'harbour_managed') {
            return new OrcaInstallation($existing, 'unchanged');
        }
        if ($classification === 'conflicting') {
            $qualification = $reconfigure
                ? ' The file is not proven to be Harbour-generated, so --reconfigure cannot replace it.'
                : '';
            throw new HarbourException(
                ErrorCode::IntegrationConflict,
                "Existing Orca scripts.setup or scripts.archive conflicts with Harbour lifecycle ownership. Harbour did not change it.{$qualification}\nMerge this exact block manually:\n".self::MANUAL_MERGE,
                [
                    'integration' => 'orca',
                    'configuration' => $classification,
                    'path' => self::CONFIGURATION_PATH,
                    'manual_merge' => self::MANUAL_MERGE,
                ],
            );
        }

        $contents = $classification === 'partial'
            ? $this->completeScriptsMapping($existing, $configuration)
            : $this->appendConfiguration($existing);
        $this->validateCandidate($contents);

        return new OrcaInstallation($contents, $exists ? 'updated' : 'created');
    }

    public function install(OrcaInstallation $installation): void
    {
        if ($installation->change === 'unchanged') {
            return;
        }

        $this->files->write($this->configurationPath(), $installation->contents, 0644);
        $this->validateCandidate($installation->contents);
    }

    public function uninstall(): OrcaUninstallation
    {
        $path = $this->configurationPath();
        if (! file_exists($path) && ! is_link($path)) {
            return new OrcaUninstallation('absent');
        }
        try {
            $this->assertSafePath($path);
        } catch (HarbourException) {
            return new OrcaUninstallation('retained');
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return new OrcaUninstallation('retained');
        }

        $newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $managed = str_replace("\n", $newline, self::CONFIGURATION).$newline;
        if ($contents === $managed) {
            if (! @unlink($path)) {
                throw new HarbourException(ErrorCode::UnsafeOperation, 'Unable to remove Harbour-managed Orca project configuration.');
            }

            return new OrcaUninstallation('removed');
        }
        $suffix = $newline.$managed;
        if (str_ends_with($contents, $suffix)) {
            $this->files->write($path, substr($contents, 0, -strlen($suffix)), 0644);

            return new OrcaUninstallation('removed');
        }
        if (! str_contains($contents, self::MARKER)) {
            return new OrcaUninstallation('absent');
        }

        $updated = $this->removeManagedScriptLines($contents, $newline);
        if ($updated === null) {
            return new OrcaUninstallation('retained');
        }
        $this->files->write($path, $updated, 0644);

        return new OrcaUninstallation('removed');
    }

    /**
     * @return array{
     *     state: string,
     *     capability: string,
     *     configured: bool,
     *     configuration: string,
     *     management: string,
     *     configuration_path: string,
     *     tool: array{available: bool, version: ?string, supported: bool, supported_range: string, capabilities: array{setup_policy: bool, archive_hooks: bool, blocking_archive: bool}},
     *     hooks: array{setup: bool, archive: bool},
     *     conflicts: list<string>,
     *     removal_command: string
     * }
     */
    public function status(): array
    {
        $tool = $this->toolStatus();
        $path = $this->configurationPath();
        $classification = 'absent';
        $conflicts = [];
        $scripts = [];

        try {
            $this->assertSafePath($path);
            $exists = is_file($path);
            $contents = $exists ? @file_get_contents($path) : '';
            if ($contents === false) {
                throw new HarbourException(ErrorCode::UnsafeOperation, 'Unable to read Orca project configuration.');
            }
            $configuration = $this->configuration($contents, $exists);
            $classification = $this->classify($configuration, $contents, $exists);
            $scripts = $this->scripts($configuration);
            if ($classification === 'conflicting') {
                $conflicts[] = 'scripts.setup/scripts.archive are not the exact Harbour lifecycle';
            }
        } catch (HarbourException $exception) {
            $classification = $exception->errorCode === ErrorCode::UnsafeOperation ? 'unsafe' : 'invalid';
            $conflicts[] = $exception->getMessage();
        }

        $configured = in_array($classification, ['equivalent', 'harbour_managed'], true);
        $state = ! $tool['supported']
            ? 'unsupported'
            : ($configured ? 'fully_configured' : ($classification === 'harbour_managed' ? 'harbour_managed' : $classification));

        return [
            'state' => $state,
            'capability' => $tool['supported'] ? 'full_lifecycle' : 'unsupported',
            'configured' => $configured && $tool['supported'],
            'configuration' => $classification,
            'management' => $classification === 'harbour_managed' ? 'harbour' : 'project',
            'configuration_path' => self::CONFIGURATION_PATH,
            'tool' => $tool,
            'hooks' => [
                'setup' => ($scripts['setup'] ?? null) === self::SETUP,
                'archive' => ($scripts['archive'] ?? null) === self::ARCHIVE,
            ],
            'conflicts' => $conflicts,
            'removal_command' => 'orca worktree rm --worktree … --run-hooks',
        ];
    }

    private function assertSupportedTool(): void
    {
        $tool = $this->toolStatus();
        if (! $tool['available']) {
            throw new HarbourException(
                ErrorCode::IntegrationUnavailable,
                'Orca is required for --worktree-hooks=orca, but its headless command schema is unavailable.',
                ['integration' => 'orca', 'supported_range' => $tool['supported_range']],
            );
        }
        if (! $tool['supported']) {
            $version = $tool['version'] === null ? '' : " {$tool['version']}";
            throw new HarbourException(
                ErrorCode::IntegrationUnavailable,
                "Orca{$version} does not expose Harbour's required setup-policy and verified blocking-archive capabilities. Current Orca releases delete the checkout after an archive hook fails; see stablyai/orca#19334.",
                [
                    'integration' => 'orca',
                    'tool_version' => $tool['version'],
                    'supported_range' => $tool['supported_range'],
                    'capabilities' => $tool['capabilities'],
                ],
            );
        }
    }

    /** @return array{available: bool, version: ?string, supported: bool, supported_range: string, capabilities: array{setup_policy: bool, archive_hooks: bool, blocking_archive: bool}} */
    private function toolStatus(): array
    {
        $commands = $this->commandSchema();
        $setupPolicy = $this->commandHasFlag($commands, 'worktree create', 'setup');
        $archiveHooks = $this->commandHasFlag($commands, 'worktree rm', 'run-hooks');
        $runtime = $this->runtimeStatus();
        $version = $runtime['version'];
        $blockingArchive = $archiveHooks && $runtime['archive_failure_blocks_removal'];
        $versionSupported = $version !== null && $this->supports($version);
        $available = $commands !== null;

        return [
            'available' => $available,
            'version' => $version,
            'supported' => $available && $setupPolicy && $blockingArchive && $versionSupported,
            'supported_range' => 'no validated release; requires >=1.4.184 <1.5.0 plus a machine-readable archive-failure-blocking capability (stablyai/orca#19334)',
            'capabilities' => [
                'setup_policy' => $setupPolicy,
                'archive_hooks' => $archiveHooks,
                'blocking_archive' => $blockingArchive,
            ],
        ];
    }

    /** @return list<array<mixed>>|null */
    private function commandSchema(): ?array
    {
        try {
            $result = $this->runner->run([$this->binary(), 'agent-context', '--json'], $this->workspacePath);
        } catch (Throwable) {
            return null;
        }
        if (! $result->successful()) {
            return null;
        }
        try {
            $decoded = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        $commands = is_array($decoded) ? ($decoded['commands'] ?? null) : null;
        if (! is_array($commands) || $commands === [] || ! array_is_list($commands)) {
            return null;
        }
        $normalized = [];
        foreach ($commands as $command) {
            if (! is_array($command)) {
                return null;
            }
            $normalized[] = $command;
        }

        return $normalized;
    }

    /** @param list<array<mixed>>|null $commands */
    private function commandHasFlag(?array $commands, string $name, string $flag): bool
    {
        foreach ($commands ?? [] as $command) {
            if (($command['command'] ?? null) !== $name) {
                continue;
            }
            $flags = $command['flags'] ?? null;

            return is_array($flags) && in_array($flag, $flags, true);
        }

        return false;
    }

    /** @return array{version: ?string, archive_failure_blocks_removal: bool} */
    private function runtimeStatus(): array
    {
        try {
            $result = $this->runner->run([$this->binary(), 'status', '--json'], $this->workspacePath);
        } catch (Throwable) {
            return ['version' => null, 'archive_failure_blocks_removal' => false];
        }
        if (! $result->successful()) {
            return ['version' => null, 'archive_failure_blocks_removal' => false];
        }
        try {
            $decoded = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['version' => null, 'archive_failure_blocks_removal' => false];
        }
        if (! is_array($decoded)) {
            return ['version' => null, 'archive_failure_blocks_removal' => false];
        }
        $result = $decoded['result'] ?? null;
        $runtime = is_array($result) ? ($result['runtime'] ?? null) : null;
        $version = is_array($runtime) ? ($runtime['appVersion'] ?? null) : null;
        $capabilities = is_array($runtime) ? ($runtime['capabilities'] ?? null) : null;

        return [
            'version' => is_string($version) && preg_match('/^\d+\.\d+\.\d+$/', $version) === 1 ? $version : null,
            // This capability is deliberately fail-closed. Merely exposing
            // --run-hooks is insufficient: Orca <=1.4.197 logs archive errors
            // and then deletes the checkout (stablyai/orca#19334).
            'archive_failure_blocks_removal' => is_array($capabilities)
                && in_array(self::BLOCKING_ARCHIVE_CAPABILITY, $capabilities, true),
        ];
    }

    private function supports(string $version): bool
    {
        return version_compare($version, self::MINIMUM_VERSION, '>=')
            && version_compare($version, self::MAXIMUM_VERSION, '<');
    }

    /** @return array<string, mixed> */
    private function configuration(string $contents, bool $exists): array
    {
        if (! $exists || trim($contents) === '') {
            return [];
        }
        if (strlen($contents) > self::MAXIMUM_CONFIGURATION_BYTES) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, 'Orca project configuration exceeds the supported 256 KiB limit.');
        }
        try {
            $configuration = Yaml::parse($contents, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $exception) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, 'Orca project configuration is malformed: '.$exception->getMessage(), [], $exception);
        }
        if (! is_array($configuration) || array_is_list($configuration)) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, 'Orca project configuration must be a YAML mapping.');
        }

        $normalized = [];
        foreach ($configuration as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /** @param array<string, mixed> $configuration */
    private function classify(array $configuration, string $contents, bool $exists): string
    {
        if (! array_key_exists('scripts', $configuration)) {
            return 'absent';
        }
        $scripts = $this->scripts($configuration);
        if ($scripts === []) {
            return $configuration['scripts'] === null || (is_array($configuration['scripts']) && $configuration['scripts'] === [])
                ? 'partial'
                : 'conflicting';
        }
        $hasSetup = array_key_exists('setup', $scripts);
        $hasArchive = array_key_exists('archive', $scripts);
        $setupMatches = ! $hasSetup || $scripts['setup'] === self::SETUP;
        $archiveMatches = ! $hasArchive || $scripts['archive'] === self::ARCHIVE;
        if (! $setupMatches || ! $archiveMatches) {
            return 'conflicting';
        }
        if (! $hasSetup || ! $hasArchive) {
            return 'partial';
        }

        return $exists && str_contains($contents, self::MARKER) ? 'harbour_managed' : 'equivalent';
    }

    /** @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    private function scripts(array $configuration): array
    {
        $scripts = $configuration['scripts'] ?? null;
        if (! is_array($scripts) || array_is_list($scripts)) {
            return [];
        }

        $normalized = [];
        foreach ($scripts as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function validateCandidate(string $contents): void
    {
        $configuration = $this->configuration($contents, true);
        if (! in_array($this->classify($configuration, $contents, true), ['equivalent', 'harbour_managed'], true)) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, 'The proposed Orca configuration does not resolve to the exact Harbour lifecycle.');
        }
    }

    /** @param array<string, mixed> $configuration */
    private function completeScriptsMapping(string $existing, array $configuration): string
    {
        if (preg_match('/^scripts:[ \t]*(?:#.*)?\r?$/m', $existing) !== 1) {
            throw new HarbourException(
                ErrorCode::IntegrationConflict,
                "Harbour cannot safely add the missing Orca lifecycle key without rewriting YAML. Harbour did not change it.\nMerge this exact block manually:\n".self::MANUAL_MERGE,
                ['integration' => 'orca', 'configuration' => 'conflicting', 'path' => self::CONFIGURATION_PATH, 'manual_merge' => self::MANUAL_MERGE],
            );
        }

        $scripts = $this->scripts($configuration);
        $lines = preg_split('/(?<=\n)/', $existing);
        if (! is_array($lines)) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, 'Unable to inspect Orca project configuration lines.');
        }
        $scriptsLine = null;
        foreach ($lines as $index => $line) {
            if (preg_match('/^scripts:[ \t]*(?:#.*)?(?:\r?\n)?$/', $line) === 1) {
                $scriptsLine = $index;
                break;
            }
        }
        if ($scriptsLine === null) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, 'Unable to locate the Orca scripts mapping safely.');
        }
        $insertAt = count($lines);
        for ($index = $scriptsLine + 1; $index < count($lines); $index++) {
            if (preg_match('/^[^\s#]/', $lines[$index]) === 1) {
                $insertAt = $index;
                break;
            }
        }
        $newline = str_contains($existing, "\r\n") ? "\r\n" : "\n";
        $addition = '  '.self::MARKER.$newline;
        if (! array_key_exists('setup', $scripts)) {
            $addition .= '  setup: '.self::SETUP.$newline;
        }
        if (! array_key_exists('archive', $scripts)) {
            $addition .= '  archive: '.self::ARCHIVE.$newline;
        }
        if (! str_ends_with($lines[$insertAt - 1], "\n")) {
            $addition = $newline.$addition;
        }
        array_splice($lines, $insertAt, 0, [$addition]);

        return implode('', $lines);
    }

    private function appendConfiguration(string $existing): string
    {
        $newline = str_contains($existing, "\r\n") ? "\r\n" : "\n";
        $configuration = str_replace("\n", $newline, self::CONFIGURATION).$newline;
        if ($existing === '') {
            return $configuration;
        }

        return $existing.(str_ends_with($existing, "\n") ? '' : $newline).$newline.$configuration;
    }

    private function removeManagedScriptLines(string $contents, string $newline): ?string
    {
        $quotedNewline = preg_quote($newline, '/');
        $marker = preg_quote('  '.self::MARKER, '/');
        $setup = preg_quote('  setup: '.self::SETUP, '/');
        $archive = preg_quote('  archive: '.self::ARCHIVE, '/');
        $lifecycleKey = preg_quote('  setup:', '/').'|'.preg_quote('  archive:', '/');
        $pattern = '/'.$marker.$quotedNewline.'(?:'.$setup.$quotedNewline.'(?:'.$archive.$quotedNewline.')?|'.$archive.$quotedNewline.')(?!'.$lifecycleKey.')/';
        $count = 0;
        $updated = preg_replace($pattern, '', $contents, 1, $count);

        return is_string($updated) && $count === 1 ? $updated : null;
    }

    private function assertSafePath(string $path): void
    {
        if (is_link($path) || (file_exists($path) && ! is_file($path))) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'Orca project configuration must be a regular project file.');
        }
    }

    private function configurationPath(): string
    {
        return rtrim($this->workspacePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.self::CONFIGURATION_PATH;
    }

    private function binary(): string
    {
        if ($this->binary !== null) {
            return $this->binary;
        }

        return PHP_OS_FAMILY === 'Linux' ? 'orca-ide' : 'orca';
    }
}
