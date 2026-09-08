<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Integrations\Orca\OrcaInstallation;
use PickeringTech\Harbour\Integrations\Orca\OrcaIntegration;
use PickeringTech\Harbour\Process\ProcessResult;
use RuntimeException;

final class OrcaIntegrationTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/harbour-orca-'.bin2hex(random_bytes(6));
        mkdir($this->workspace, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);
    }

    public function test_it_creates_the_golden_blocking_lifecycle_configuration(): void
    {
        $runner = new FakeOrcaRunner;
        $integration = new OrcaIntegration($this->workspace, $runner, binary: 'orca-test');

        $installation = $integration->prepare();

        self::assertSame('created', $installation->change);
        self::assertSame($this->fixture('generated.yaml'), $installation->contents);
        self::assertSame([
            ['orca-test', 'agent-context', '--json'],
            ['orca-test', 'status', '--json'],
        ], $runner->commands);

        $integration->install($installation);
        self::assertSame($installation->contents, file_get_contents($this->workspace.'/orca.yaml'));

        $status = $integration->status();
        self::assertSame('fully_configured', $status['state']);
        self::assertSame('harbour_managed', $status['configuration']);
        self::assertSame('harbour', $status['management']);
        self::assertSame('full_lifecycle', $status['capability']);
        self::assertTrue($status['configured']);
        self::assertTrue($status['hooks']['setup']);
        self::assertTrue($status['hooks']['archive']);
        self::assertSame('1.4.197', $status['tool']['version']);
        self::assertSame('orca worktree rm --worktree … --run-hooks', $status['removal_command']);
    }

    public function test_install_validates_a_candidate_before_writing_it(): void
    {
        $integration = new OrcaIntegration($this->workspace, new FakeOrcaRunner);

        try {
            $integration->install(new OrcaInstallation("scripts:\n  setup: npm install\n", 'created'));
            self::fail('Expected an invalid prepared candidate to be rejected.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::InvalidConfiguration, $exception->errorCode);
        }

        self::assertFileDoesNotExist($this->workspace.'/orca.yaml');
    }

    public function test_it_preserves_unrelated_yaml_and_comments_when_appending_scripts(): void
    {
        $original = $this->fixture('unrelated.yaml');
        file_put_contents($this->workspace.'/orca.yaml', $original);
        $integration = new OrcaIntegration($this->workspace, new FakeOrcaRunner);

        $installation = $integration->prepare();
        $integration->install($installation);

        self::assertSame('updated', $installation->change);
        self::assertStringStartsWith($original."\n", $installation->contents);
        self::assertStringContainsString(OrcaIntegration::SETUP, $installation->contents);
        self::assertStringContainsString(OrcaIntegration::ARCHIVE, $installation->contents);
    }

    public function test_it_adds_only_a_missing_key_to_a_simple_scripts_mapping(): void
    {
        $original = $this->fixture('partial.yaml');
        file_put_contents($this->workspace.'/orca.yaml', $original);
        $integration = new OrcaIntegration($this->workspace, new FakeOrcaRunner);

        $installation = $integration->prepare();
        $integration->install($installation);

        self::assertSame('updated', $installation->change);
        self::assertStringContainsString("  lint: composer format:check\n", $installation->contents);
        self::assertSame(1, substr_count($installation->contents, OrcaIntegration::SETUP));
        self::assertSame(1, substr_count($installation->contents, OrcaIntegration::ARCHIVE));
        self::assertStringContainsString("defaultTabs:\n", $installation->contents);

        self::assertSame('removed', $integration->uninstall()->change);
        self::assertSame($original, file_get_contents($this->workspace.'/orca.yaml'));
    }

    public function test_it_completes_an_empty_scripts_mapping_and_preserves_crlf(): void
    {
        $original = "scripts:\r\nnext: value\r\n";
        file_put_contents($this->workspace.'/orca.yaml', $original);
        $integration = new OrcaIntegration($this->workspace, new FakeOrcaRunner);

        $installation = $integration->prepare();

        self::assertStringContainsString('  setup: '.OrcaIntegration::SETUP."\r\n", $installation->contents);
        self::assertStringContainsString('  archive: '.OrcaIntegration::ARCHIVE."\r\nnext: value", $installation->contents);
    }

    public function test_it_completes_a_scripts_mapping_without_a_trailing_newline(): void
    {
        $original = 'scripts:'."\n".'  setup: '.OrcaIntegration::SETUP;
        file_put_contents($this->workspace.'/orca.yaml', $original);

        $installation = (new OrcaIntegration($this->workspace, new FakeOrcaRunner))->prepare();

        self::assertStringContainsString(OrcaIntegration::SETUP."\n  ".'# Harbour Orca lifecycle integration.', $installation->contents);
        self::assertStringContainsString('  archive: '.OrcaIntegration::ARCHIVE."\n", $installation->contents);
    }

    public function test_it_recognizes_equivalent_project_configuration_without_mutation(): void
    {
        $original = $this->fixture('equivalent.yaml');
        file_put_contents($this->workspace.'/orca.yaml', $original);
        $integration = new OrcaIntegration($this->workspace, new FakeOrcaRunner);

        $installation = $integration->prepare();
        $integration->install($installation);

        self::assertSame('unchanged', $installation->change);
        self::assertSame($original, file_get_contents($this->workspace.'/orca.yaml'));
        self::assertSame('equivalent', $integration->status()['configuration']);
    }

    public function test_it_refuses_conflicting_scripts_with_exact_manual_merge_guidance(): void
    {
        $original = $this->fixture('conflicting.yaml');
        file_put_contents($this->workspace.'/orca.yaml', $original);
        $integration = new OrcaIntegration($this->workspace, new FakeOrcaRunner);

        try {
            $integration->prepare(true);
            self::fail('Expected an Orca hook conflict.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::IntegrationConflict, $exception->errorCode);
            self::assertStringContainsString('--reconfigure cannot replace it', $exception->getMessage());
            self::assertSame(OrcaIntegration::MANUAL_MERGE, $exception->context['manual_merge']);
        }
        self::assertSame($original, file_get_contents($this->workspace.'/orca.yaml'));
    }

    public function test_it_refuses_flow_style_partial_scripts_because_editing_is_not_demonstrably_safe(): void
    {
        $original = 'scripts: { setup: "'.OrcaIntegration::SETUP."\" }\n";
        file_put_contents($this->workspace.'/orca.yaml', $original);

        try {
            (new OrcaIntegration($this->workspace, new FakeOrcaRunner))->prepare();
            self::fail('Expected a manual merge requirement.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::IntegrationConflict, $exception->errorCode);
            self::assertStringContainsString('cannot safely add', $exception->getMessage());
        }
        self::assertSame($original, file_get_contents($this->workspace.'/orca.yaml'));
    }

    /** @param list<array<mixed>>|null $commands */
    #[DataProvider('unsupportedTools')]
    public function test_it_rejects_missing_unsupported_or_out_of_range_orca(
        ?array $commands,
        ?string $version,
    ): void {
        $runner = new FakeOrcaRunner($commands, $version);
        $integration = new OrcaIntegration($this->workspace, $runner);

        try {
            $integration->prepare();
            self::fail('Expected unsupported Orca.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::IntegrationUnavailable, $exception->errorCode);
        }
        self::assertFileDoesNotExist($this->workspace.'/orca.yaml');
    }

    /** @return iterable<string, array{?array<mixed>, ?string}> */
    public static function unsupportedTools(): iterable
    {
        yield 'missing schema' => [null, null];
        yield 'missing setup policy' => [[self::schemaCommand('worktree rm', ['run-hooks'])], '1.4.197'];
        yield 'missing archive policy' => [[self::schemaCommand('worktree create', ['setup'])], '1.4.197'];
        yield 'old version' => [self::schema(), '1.4.183'];
        yield 'next minor' => [self::schema(), '1.5.0'];
    }

    public function test_a_machine_readable_blocking_capability_and_version_are_both_required(): void
    {
        $integration = new OrcaIntegration($this->workspace, new FakeOrcaRunner(version: null));

        try {
            $integration->prepare();
            self::fail('Expected a runtime without a validated version to be rejected.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::IntegrationUnavailable, $exception->errorCode);
        }
        self::assertFalse($integration->status()['tool']['supported']);
        self::assertNull($integration->status()['tool']['version']);
    }

    public function test_run_hooks_alone_is_not_misreported_as_blocking_archive_support(): void
    {
        $runner = new FakeOrcaRunner;
        $runner->blockingArchiveCapability = false;
        $integration = new OrcaIntegration($this->workspace, $runner);

        $status = $integration->status();

        self::assertTrue($status['tool']['capabilities']['archive_hooks']);
        self::assertFalse($status['tool']['capabilities']['blocking_archive']);
        self::assertFalse($status['tool']['supported']);
        self::assertSame('unsupported', $status['state']);
    }

    #[DataProvider('invalidConfigurations')]
    public function test_it_rejects_malformed_oversized_and_non_mapping_yaml(string $contents): void
    {
        file_put_contents($this->workspace.'/orca.yaml', $contents);
        $integration = new OrcaIntegration($this->workspace, new FakeOrcaRunner);

        try {
            $integration->prepare();
            self::fail('Expected invalid Orca YAML.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::InvalidConfiguration, $exception->errorCode);
        }
        self::assertSame($contents, file_get_contents($this->workspace.'/orca.yaml'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidConfigurations(): iterable
    {
        yield 'malformed' => [(string) file_get_contents(__DIR__.'/../Fixtures/Orca/malformed.yaml')];
        yield 'sequence root' => ["- scripts\n- invalid\n"];
        yield 'oversized' => [str_repeat('# padding', 32769)];
    }

    public function test_it_refuses_symlinked_and_non_regular_configuration_paths(): void
    {
        $outside = $this->workspace.'/outside.yaml';
        file_put_contents($outside, "scripts: {}\n");
        symlink($outside, $this->workspace.'/orca.yaml');
        $integration = new OrcaIntegration($this->workspace, new FakeOrcaRunner);

        try {
            $integration->prepare();
            self::fail('Expected the symlink to be refused.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::UnsafeOperation, $exception->errorCode);
        }
        self::assertSame('retained', $integration->uninstall()->change);
        self::assertSame('unsafe', $integration->status()['configuration']);

        unlink($this->workspace.'/orca.yaml');
        mkdir($this->workspace.'/orca.yaml');
        try {
            $integration->prepare();
            self::fail('Expected a directory to be refused.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::UnsafeOperation, $exception->errorCode);
        }
    }

    public function test_unreadable_configuration_fails_closed_for_prepare_status_and_uninstall(): void
    {
        $path = $this->workspace.'/orca.yaml';
        file_put_contents($path, $this->fixture('generated.yaml'));
        chmod($path, 0000);
        $integration = new OrcaIntegration($this->workspace, new FakeOrcaRunner);

        try {
            self::assertSame('retained', $integration->uninstall()->change);
            try {
                $integration->prepare();
                self::fail('Unreadable configuration must fail preparation.');
            } catch (HarbourException $exception) {
                self::assertSame(ErrorCode::UnsafeOperation, $exception->errorCode);
            }
            self::assertSame('unsafe', $integration->status()['configuration']);
        } finally {
            chmod($path, 0600);
        }
    }

    public function test_uninstall_reports_an_undeletable_managed_configuration(): void
    {
        $path = $this->workspace.'/orca.yaml';
        file_put_contents($path, $this->fixture('generated.yaml'));
        chmod($this->workspace, 0500);
        $integration = new OrcaIntegration($this->workspace, new FakeOrcaRunner);

        try {
            $integration->uninstall();
            self::fail('An undeletable managed configuration must fail uninstallation.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::UnsafeOperation, $exception->errorCode);
        } finally {
            chmod($this->workspace, 0700);
        }
    }

    public function test_status_reports_absent_conflicting_invalid_and_unsupported_states(): void
    {
        $integration = new OrcaIntegration($this->workspace, new FakeOrcaRunner);
        self::assertSame('absent', $integration->status()['state']);

        file_put_contents($this->workspace.'/orca.yaml', $this->fixture('conflicting.yaml'));
        $status = $integration->status();
        self::assertSame('conflicting', $status['state']);
        self::assertNotSame([], $status['conflicts']);

        file_put_contents($this->workspace.'/orca.yaml', $this->fixture('malformed.yaml'));
        self::assertSame('invalid', $integration->status()['state']);

        unlink($this->workspace.'/orca.yaml');
        $unsupported = new OrcaIntegration($this->workspace, new FakeOrcaRunner([]));
        self::assertSame('unsupported', $unsupported->status()['state']);
        self::assertSame('unsupported', $unsupported->status()['capability']);
        self::assertFalse($unsupported->status()['configured']);
    }

    public function test_uninstall_removes_only_exact_harbour_owned_content(): void
    {
        $integration = new OrcaIntegration($this->workspace, new FakeOrcaRunner);
        self::assertSame('absent', $integration->uninstall()->change);

        $integration->install($integration->prepare());
        self::assertSame('removed', $integration->uninstall()->change);
        self::assertFileDoesNotExist($this->workspace.'/orca.yaml');

        file_put_contents($this->workspace.'/orca.yaml', $this->fixture('equivalent.yaml'));
        self::assertSame('absent', $integration->uninstall()->change);
        self::assertFileExists($this->workspace.'/orca.yaml');

        file_put_contents($this->workspace.'/orca.yaml', str_replace(OrcaIntegration::SETUP, 'composer update', $this->fixture('harbour-owned.yaml')));
        self::assertSame('retained', $integration->uninstall()->change);
    }

    public function test_uninstall_removes_an_appended_owned_block_and_preserves_unrelated_yaml(): void
    {
        $original = $this->fixture('unrelated.yaml');
        file_put_contents($this->workspace.'/orca.yaml', $original);
        $integration = new OrcaIntegration($this->workspace, new FakeOrcaRunner);
        $integration->install($integration->prepare());

        self::assertSame('removed', $integration->uninstall()->change);
        self::assertSame($original, file_get_contents($this->workspace.'/orca.yaml'));
    }

    public function test_uninstall_retains_a_modified_partially_inserted_owned_block(): void
    {
        $original = $this->fixture('partial.yaml');
        file_put_contents($this->workspace.'/orca.yaml', $original);
        $integration = new OrcaIntegration($this->workspace, new FakeOrcaRunner);
        $integration->install($integration->prepare());
        $modified = str_replace(OrcaIntegration::ARCHIVE, 'composer custom:teardown', (string) file_get_contents($this->workspace.'/orca.yaml'));
        file_put_contents($this->workspace.'/orca.yaml', $modified);

        self::assertSame('retained', $integration->uninstall()->change);
        self::assertSame($modified, file_get_contents($this->workspace.'/orca.yaml'));
    }

    #[DataProvider('hostilePaths')]
    public function test_paths_never_enter_generated_commands_or_shell_strings(string $suffix): void
    {
        $workspace = $this->workspace.'/'.$suffix;
        mkdir($workspace, 0700, true);
        $runner = new FakeOrcaRunner;
        $integration = new OrcaIntegration($workspace, $runner, binary: 'orca-test');

        $installation = $integration->prepare();

        self::assertSame($workspace, $runner->workingDirectories[0]);
        self::assertSame(['orca-test', 'agent-context', '--json'], $runner->commands[0]);
        self::assertStringNotContainsString($workspace, $installation->contents);
    }

    /** @return iterable<string, array{string}> */
    public static function hostilePaths(): iterable
    {
        yield 'unicode and spaces' => ['work tree ü'];
        yield 'quotes' => ["single'and\"double"];
        yield 'newline' => ["line\nbreak"];
        yield 'shell metacharacters' => ['$(touch owned); & | `echo bad`'];
    }

    public function test_external_command_failures_and_invalid_json_are_reported_as_unavailable(): void
    {
        $runner = new FakeOrcaRunner;
        $runner->throw = true;
        $integration = new OrcaIntegration($this->workspace, $runner);
        self::assertFalse($integration->status()['tool']['available']);

        $runner->throw = false;
        $runner->schemaOutput = '{invalid';
        self::assertFalse($integration->status()['tool']['available']);

        $runner->schemaOutput = 'null';
        self::assertFalse($integration->status()['tool']['available']);

        $runner->schemaOutput = '{"commands":{}}';
        self::assertFalse($integration->status()['tool']['available']);

        $runner->schemaOutput = '{"commands":[{"command":"worktree create","flags":["setup"]},false]}';
        self::assertFalse($integration->status()['tool']['available']);

        $runner->schemaOutput = null;
        $runner->statusOutput = '{invalid';
        self::assertNull($integration->status()['tool']['version']);

        $runner->statusOutput = 'null';
        self::assertNull($integration->status()['tool']['version']);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/Orca/'.$name);
    }

    /** @return list<array<mixed>> */
    private static function schema(): array
    {
        return [
            self::schemaCommand('worktree create', ['setup']),
            self::schemaCommand('worktree rm', ['run-hooks']),
        ];
    }

    /** @param list<string> $flags
     * @return array{command: string, flags: list<string>}
     */
    private static function schemaCommand(string $command, array $flags): array
    {
        return ['command' => $command, 'flags' => $flags];
    }

    private function removeDirectory(string $path): void
    {
        if (is_link($path)) {
            unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.'/'.$entry;
            is_dir($child) && ! is_link($child) ? $this->removeDirectory($child) : @unlink($child);
        }
        @rmdir($path);
    }
}

final class FakeOrcaRunner implements CommandRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    /** @var list<string> */
    public array $workingDirectories = [];

    public bool $throw = false;

    public ?string $schemaOutput = null;

    public ?string $statusOutput = null;

    public bool $blockingArchiveCapability = true;

    /** @param list<array<mixed>>|null $schema */
    public function __construct(
        private readonly ?array $schema = [
            ['command' => 'worktree create', 'flags' => ['setup']],
            ['command' => 'worktree rm', 'flags' => ['run-hooks']],
        ],
        private readonly ?string $version = '1.4.197',
    ) {}

    public function run(array $command, string $workingDirectory, array $environment = [], ?callable $output = null): ProcessResult
    {
        $this->commands[] = $command;
        $this->workingDirectories[] = $workingDirectory;
        if ($this->throw) {
            throw new RuntimeException('Orca unavailable');
        }
        if ($command[1] === 'agent-context') {
            if ($this->schema === null && $this->schemaOutput === null) {
                return new ProcessResult(1, '', 'unavailable');
            }

            return new ProcessResult(0, $this->schemaOutput ?? (string) json_encode(['commands' => $this->schema], JSON_THROW_ON_ERROR));
        }
        if ($this->version === null && $this->statusOutput === null) {
            return new ProcessResult(1, '', 'runtime unavailable');
        }

        return new ProcessResult(0, $this->statusOutput ?? (string) json_encode([
            'result' => ['runtime' => [
                'appVersion' => $this->version,
                'capabilities' => $this->blockingArchiveCapability ? [OrcaIntegration::BLOCKING_ARCHIVE_CAPABILITY] : [],
            ]],
        ], JSON_THROW_ON_ERROR));
    }
}
