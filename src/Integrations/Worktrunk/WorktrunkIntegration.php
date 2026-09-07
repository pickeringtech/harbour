<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Integrations\Worktrunk;

use JsonException;
use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Support\AtomicFile;
use Throwable;

final readonly class WorktrunkIntegration
{
    public const CONFIGURATION_PATH = '.config/wt.toml';

    public const MINIMUM_VERSION = '0.76.0';

    public const MAXIMUM_VERSION = '0.77.0';

    public const COMPOSER_INSTALL = 'composer install';

    public const SETUP = 'composer workspace:setup';

    public const TEARDOWN = 'composer workspace:teardown -- --force';

    private const CONFIGURATION = <<<'TOML'
    # Harbour Worktrunk lifecycle integration.
    # Setup is blocking and ordered: dependencies must exist before Harbour starts.
    [[pre-start]]
    harbour-composer-install = "composer install"

    [[pre-start]]
    harbour-setup = "composer workspace:setup"

    # Teardown must succeed while the checkout and its ownership state still exist.
    [pre-remove]
    harbour-teardown = "composer workspace:teardown -- --force"
    TOML;

    public function __construct(
        private string $workspacePath,
        private CommandRunner $runner,
        private AtomicFile $files = new AtomicFile,
        private string $binary = 'wt',
    ) {}

    public function prepare(): WorktrunkInstallation
    {
        $this->assertSupportedTool();
        $path = $this->configurationPath();
        $this->assertSafePath($path);
        $existing = is_file($path) ? file_get_contents($path) : '';
        if ($existing === false) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'Unable to read Worktrunk project configuration.');
        }

        $configuration = $this->projectConfiguration();
        if ($existing !== '') {
            $classification = $this->classify($configuration);
            if ($classification === 'equivalent') {
                return new WorktrunkInstallation($existing, 'unchanged');
            }
            if ($classification !== 'absent') {
                throw new HarbourException(
                    ErrorCode::IntegrationConflict,
                    'Existing Worktrunk pre-start or pre-remove hooks conflict with Harbour lifecycle ownership. Harbour did not change them.',
                    ['integration' => 'worktrunk', 'configuration' => $classification, 'path' => self::CONFIGURATION_PATH],
                );
            }
        }

        $contents = $this->appendConfiguration($existing);
        $this->validateCandidate($contents);

        return new WorktrunkInstallation($contents, is_file($path) ? 'updated' : 'created');
    }

    public function install(WorktrunkInstallation $installation): void
    {
        if ($installation->change === 'unchanged') {
            return;
        }

        $directory = dirname($this->configurationPath());
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'Unable to create the Worktrunk project configuration directory.');
        }
        $this->files->write($this->configurationPath(), $installation->contents, 0644);

        // The installed binary is the source of truth for TOML validity.
        $this->projectConfiguration();
    }

    public function uninstall(): WorktrunkUninstallation
    {
        $path = $this->configurationPath();
        if (! file_exists($path) && ! is_link($path)) {
            return new WorktrunkUninstallation('absent');
        }
        try {
            $this->assertSafePath($path);
        } catch (HarbourException) {
            return new WorktrunkUninstallation('retained');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            return new WorktrunkUninstallation('retained');
        }
        $managed = self::CONFIGURATION."\n";
        if ($contents === $managed) {
            if (! unlink($path)) {
                throw new HarbourException(ErrorCode::UnsafeOperation, 'Unable to remove Harbour-managed Worktrunk project configuration.');
            }

            return new WorktrunkUninstallation('removed');
        }
        $suffix = "\n".$managed;
        if (! str_ends_with($contents, $suffix)) {
            return new WorktrunkUninstallation(str_contains($contents, '# Harbour Worktrunk lifecycle integration.') ? 'retained' : 'absent');
        }

        $this->files->write($path, substr($contents, 0, -strlen($suffix)), 0644);

        return new WorktrunkUninstallation('removed');
    }

    /**
     * @return array{
     *     capability: string,
     *     configured: bool,
     *     configuration: string,
     *     configuration_path: string,
     *     tool: array{available: bool, version: ?string, supported: bool, supported_range: string},
     *     hooks: array{pre_start: array{composer_install: bool, setup: bool}, pre_remove: array{teardown: bool}},
     *     conflicts: list<string>
     * }
     */
    public function status(): array
    {
        $version = $this->version();
        $path = $this->configurationPath();
        $directory = dirname($path);
        $configuration = 'absent';
        $conflicts = [];
        $hooks = [
            'pre_start' => ['composer_install' => false, 'setup' => false],
            'pre_remove' => ['teardown' => false],
        ];

        if (is_link($directory) || (file_exists($directory) && ! is_dir($directory)) || is_link($path) || (file_exists($path) && ! is_file($path))) {
            $configuration = 'unsafe';
            $conflicts[] = self::CONFIGURATION_PATH.' is not a regular project file';
        } elseif (is_file($path)) {
            try {
                $project = $this->projectConfiguration();
                $configuration = $this->classify($project);
                $preStart = $this->commandSteps($project['pre-start'] ?? null);
                $preRemove = $this->commandSteps($project['pre-remove'] ?? null);
                $hooks = [
                    'pre_start' => [
                        'composer_install' => in_array(self::COMPOSER_INSTALL, $this->commands($preStart), true),
                        'setup' => in_array(self::SETUP, $this->commands($preStart), true),
                    ],
                    'pre_remove' => ['teardown' => in_array(self::TEARDOWN, $this->commands($preRemove), true)],
                ];
                if ($configuration === 'conflicting' || $configuration === 'partial') {
                    $conflicts[] = 'project pre-start/pre-remove hooks are not the exact Harbour lifecycle';
                }
            } catch (Throwable $exception) {
                $configuration = 'invalid';
                $conflicts[] = $exception->getMessage();
            }
        }

        return [
            'capability' => 'full_lifecycle',
            'configured' => $configuration === 'equivalent',
            'configuration' => $configuration,
            'configuration_path' => self::CONFIGURATION_PATH,
            'tool' => [
                'available' => $version !== null,
                'version' => $version,
                'supported' => $version !== null && $this->supports($version),
                'supported_range' => '>=0.76.0 <0.77.0',
            ],
            'hooks' => $hooks,
            'conflicts' => $conflicts,
        ];
    }

    private function assertSupportedTool(): void
    {
        $version = $this->version();
        if ($version === null) {
            throw new HarbourException(
                ErrorCode::IntegrationUnavailable,
                'Worktrunk is required for --worktree-hooks=worktrunk, but the wt binary is unavailable or did not report a version.',
                ['integration' => 'worktrunk', 'supported_range' => '>=0.76.0 <0.77.0'],
            );
        }
        if (! $this->supports($version)) {
            throw new HarbourException(
                ErrorCode::IntegrationUnavailable,
                "Worktrunk {$version} is outside Harbour's supported range (>=0.76.0 <0.77.0).",
                ['integration' => 'worktrunk', 'tool_version' => $version, 'supported_range' => '>=0.76.0 <0.77.0'],
            );
        }
    }

    private function version(): ?string
    {
        try {
            $result = $this->runner->run([$this->binary, '--version'], $this->workspacePath);
        } catch (Throwable) {
            return null;
        }
        if (! $result->successful() || preg_match('/\b(?:v)?(\d+\.\d+\.\d+)\b/', $result->output, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function supports(string $version): bool
    {
        return version_compare($version, self::MINIMUM_VERSION, '>=')
            && version_compare($version, self::MAXIMUM_VERSION, '<');
    }

    private function validateCandidate(string $contents): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'harbour-wt-');
        if ($temporary === false) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'Unable to create a temporary Worktrunk validation file.');
        }

        try {
            $this->files->write($temporary, $contents, 0600);
            $result = $this->runner->run([
                $this->binary,
                '--config',
                $temporary,
                '-C',
                $this->workspacePath,
                'config',
                'show',
                '--format=json',
            ], $this->workspacePath);
            if (! $result->successful()) {
                throw new HarbourException(
                    ErrorCode::InvalidConfiguration,
                    'Worktrunk rejected the proposed project configuration.',
                    ['integration' => 'worktrunk', 'path' => self::CONFIGURATION_PATH, 'stderr' => $result->errorOutput],
                );
            }
            $decoded = $this->decodedConfiguration($result->output);
            $user = $decoded['user'] ?? null;
            $configuration = is_array($user) ? ($user['config'] ?? null) : null;
            if ($this->classify($this->normalizedConfiguration($configuration)) !== 'equivalent') {
                throw new HarbourException(ErrorCode::InvalidConfiguration, 'Worktrunk did not resolve the proposed Harbour lifecycle as expected.');
            }
        } finally {
            @unlink($temporary);
        }
    }

    /** @return array<string, mixed> */
    private function projectConfiguration(): array
    {
        $result = $this->runner->run([$this->binary, '-C', $this->workspacePath, 'config', 'show', '--format=json'], $this->workspacePath);
        if (! $result->successful()) {
            throw new HarbourException(
                ErrorCode::InvalidConfiguration,
                'Worktrunk rejected the project configuration.',
                ['integration' => 'worktrunk', 'path' => self::CONFIGURATION_PATH, 'stderr' => $result->errorOutput],
            );
        }

        $decoded = $this->decodedConfiguration($result->output);
        $project = $decoded['project'] ?? null;
        $projectPath = is_array($project) ? ($project['path'] ?? null) : null;
        if (! is_string($projectPath)) {
            throw new HarbourException(
                ErrorCode::IntegrationUnavailable,
                'Worktrunk project hooks require a Git repository with a project configuration path.',
                ['integration' => 'worktrunk', 'path' => self::CONFIGURATION_PATH],
            );
        }
        $configuration = $project['config'] ?? null;

        return $this->normalizedConfiguration($configuration);
    }

    /** @return array<string, mixed> */
    private function normalizedConfiguration(mixed $configuration): array
    {
        if (! is_array($configuration)) {
            return [];
        }
        $normalized = [];
        foreach ($configuration as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /** @return array<mixed> */
    private function decodedConfiguration(string $output): array
    {
        try {
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, 'Worktrunk returned invalid JSON while validating project configuration.', [], $exception);
        }
        if (! is_array($decoded)) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, 'Worktrunk returned an invalid configuration document.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $configuration */
    private function classify(array $configuration): string
    {
        $hasStart = array_key_exists('pre-start', $configuration);
        $hasRemove = array_key_exists('pre-remove', $configuration);
        if (! $hasStart && ! $hasRemove) {
            return 'absent';
        }
        if (! $hasStart || ! $hasRemove) {
            return 'partial';
        }

        $start = $this->commandSteps($configuration['pre-start']);
        $remove = $this->commandSteps($configuration['pre-remove']);
        if (count($start) === 2
            && count($start[0]) === 1
            && count($start[1]) === 1
            && array_values($start[0]) === [self::COMPOSER_INSTALL]
            && array_values($start[1]) === [self::SETUP]
            && count($remove) === 1
            && count($remove[0]) === 1
            && array_values($remove[0]) === [self::TEARDOWN]) {
            return 'equivalent';
        }

        return 'conflicting';
    }

    /** @return list<array<string, string>> */
    private function commandSteps(mixed $hook): array
    {
        if (is_string($hook)) {
            return [['default' => $hook]];
        }
        if (! is_array($hook)) {
            return [];
        }
        $steps = array_is_list($hook) ? $hook : [$hook];
        $normalized = [];
        foreach ($steps as $step) {
            if (! is_array($step) || array_is_list($step)) {
                return [];
            }
            $commands = [];
            foreach ($step as $name => $command) {
                if (! is_string($name) || ! is_string($command)) {
                    return [];
                }
                $commands[$name] = $command;
            }
            $normalized[] = $commands;
        }

        return $normalized;
    }

    /** @param list<array<string, string>> $steps
     * @return list<string>
     */
    private function commands(array $steps): array
    {
        $commands = [];
        foreach ($steps as $step) {
            array_push($commands, ...array_values($step));
        }

        return $commands;
    }

    private function appendConfiguration(string $existing): string
    {
        if ($existing === '') {
            return self::CONFIGURATION."\n";
        }

        return $existing.(str_ends_with($existing, "\n") ? '' : "\n")."\n".self::CONFIGURATION."\n";
    }

    private function assertSafePath(string $path): void
    {
        $directory = dirname($path);
        if (is_link($directory) || (file_exists($directory) && ! is_dir($directory))) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'Worktrunk project configuration directory must be a real directory inside the project.');
        }
        if (is_link($path) || (file_exists($path) && ! is_file($path))) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'Worktrunk project configuration must be a regular project file.');
        }
    }

    private function configurationPath(): string
    {
        return rtrim($this->workspacePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.self::CONFIGURATION_PATH;
    }
}
