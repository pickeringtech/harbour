<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Integrations\Worktrunk\WorktrunkIntegration;
use PickeringTech\Harbour\Process\ProcessResult;

final class WorktrunkIntegrationTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/harbour-worktrunk-'.bin2hex(random_bytes(6));
        mkdir($this->workspace, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);
    }

    public function test_it_creates_and_validates_the_smallest_blocking_lifecycle_configuration(): void
    {
        $runner = new FakeWorktrunkRunner($this->workspace);
        $integration = new WorktrunkIntegration($this->workspace, $runner);

        $installation = $integration->prepare();
        self::assertSame('created', $installation->change);
        self::assertStringContainsString("[[pre-start]]\nharbour-composer-install = \"composer install\"", $installation->contents);
        self::assertStringContainsString("[[pre-start]]\nharbour-setup = \"composer workspace:setup\"", $installation->contents);
        self::assertStringContainsString("[pre-remove]\nharbour-teardown = \"composer workspace:teardown -- --force\"", $installation->contents);
        self::assertStringNotContainsString('post-start', $installation->contents);
        self::assertStringNotContainsString('post-remove', $installation->contents);

        $integration->install($installation);
        self::assertSame($installation->contents, file_get_contents($this->workspace.'/.config/wt.toml'));
        self::assertContains('--config', $runner->commands[2]);

        $status = $integration->status();
        self::assertTrue($status['configured']);
        self::assertSame('full_lifecycle', $status['capability']);
        self::assertSame('0.76.0', $status['tool']['version']);
        self::assertTrue($status['hooks']['pre_start']['composer_install']);
        self::assertTrue($status['hooks']['pre_start']['setup']);
        self::assertTrue($status['hooks']['pre_remove']['teardown']);
        self::assertSame([], $status['conflicts']);
    }

    public function test_it_preserves_unrelated_toml_and_comments_byte_for_byte(): void
    {
        mkdir($this->workspace.'/.config');
        $original = "# team preference\n[list]\nfull = true";
        file_put_contents($this->workspace.'/.config/wt.toml', $original);
        $runner = new FakeWorktrunkRunner($this->workspace, []);
        $integration = new WorktrunkIntegration($this->workspace, $runner);

        $installation = $integration->prepare();
        self::assertSame('updated', $installation->change);
        $integration->install($installation);

        $updated = (string) file_get_contents($this->workspace.'/.config/wt.toml');
        self::assertStringStartsWith($original."\n\n", $updated);
        self::assertStringContainsString('[list]', $updated);
    }

    public function test_it_recognizes_equivalent_commands_with_project_defined_names_without_rewriting(): void
    {
        mkdir($this->workspace.'/.config');
        $original = "# custom names\n[[pre-start]]\ndeps = \"composer install\"\n[[pre-start]]\nready = \"composer workspace:setup\"\n[pre-remove]\nclean = \"composer workspace:teardown -- --force\"\n";
        file_put_contents($this->workspace.'/.config/wt.toml', $original);
        $integration = new WorktrunkIntegration($this->workspace, new FakeWorktrunkRunner($this->workspace, self::equivalentConfiguration()));

        $installation = $integration->prepare();
        self::assertSame('unchanged', $installation->change);
        $integration->install($installation);
        self::assertSame($original, file_get_contents($this->workspace.'/.config/wt.toml'));
    }

    /** @param array<string, mixed> $configuration */
    #[DataProvider('conflictingConfigurations')]
    public function test_it_refuses_partial_or_custom_lifecycle_hooks(array $configuration, string $classification): void
    {
        mkdir($this->workspace.'/.config');
        $original = "# do not replace\n[pre-start]\ncustom = \"npm install\"\n";
        file_put_contents($this->workspace.'/.config/wt.toml', $original);
        $integration = new WorktrunkIntegration($this->workspace, new FakeWorktrunkRunner($this->workspace, $configuration));

        try {
            $integration->prepare();
            self::fail('Expected a Worktrunk hook conflict.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::IntegrationConflict, $exception->errorCode);
            self::assertSame($classification, $exception->context['configuration']);
        }
        self::assertSame($original, file_get_contents($this->workspace.'/.config/wt.toml'));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function conflictingConfigurations(): iterable
    {
        yield 'partial' => [['pre-start' => ['custom' => 'npm install']], 'partial'];
        yield 'custom' => [[
            'pre-start' => [['deps' => 'composer install'], ['ready' => 'composer workspace:setup']],
            'pre-remove' => ['clean' => 'rm -rf /tmp/example'],
        ], 'conflicting'];
        yield 'extra command' => [[
            'pre-start' => [['deps' => 'composer install'], ['ready' => 'composer workspace:setup', 'extra' => 'npm install']],
            'pre-remove' => ['clean' => 'composer workspace:teardown -- --force'],
        ], 'conflicting'];
        yield 'invalid pipeline step' => [[
            'pre-start' => [['composer install'], ['ready' => 'composer workspace:setup']],
            'pre-remove' => ['clean' => 'composer workspace:teardown -- --force'],
        ], 'conflicting'];
        yield 'non-string command' => [[
            'pre-start' => [['deps' => 123], ['ready' => 'composer workspace:setup']],
            'pre-remove' => ['clean' => 'composer workspace:teardown -- --force'],
        ], 'conflicting'];
    }

    #[DataProvider('unsupportedVersions')]
    public function test_it_rejects_missing_and_unsupported_worktrunk_versions(?string $version): void
    {
        $runner = new FakeWorktrunkRunner($this->workspace, [], $version);
        $integration = new WorktrunkIntegration($this->workspace, $runner);

        try {
            $integration->prepare();
            self::fail('Expected an unsupported Worktrunk version.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::IntegrationUnavailable, $exception->errorCode);
        }
        self::assertFileDoesNotExist($this->workspace.'/.config/wt.toml');
        self::assertCount(1, $runner->commands);
    }

    /** @return iterable<string, array{?string}> */
    public static function unsupportedVersions(): iterable
    {
        yield 'missing' => [null];
        yield 'older minor' => ['0.75.9'];
        yield 'next minor' => ['0.77.0'];
    }

    public function test_it_refuses_symlinked_configuration_and_directories(): void
    {
        $outside = sys_get_temp_dir().'/harbour-worktrunk-outside-'.bin2hex(random_bytes(6));
        mkdir($outside, 0700, true);
        file_put_contents($outside.'/wt.toml', "# outside\n");
        symlink($outside, $this->workspace.'/.config');
        $integration = new WorktrunkIntegration($this->workspace, new FakeWorktrunkRunner($this->workspace));

        try {
            $integration->prepare();
            self::fail('Expected the symlink to be refused.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::UnsafeOperation, $exception->errorCode);
        }
        self::assertSame("# outside\n", file_get_contents($outside.'/wt.toml'));

        unlink($this->workspace.'/.config');
        unlink($outside.'/wt.toml');
        rmdir($outside);
    }

    #[DataProvider('hostilePaths')]
    public function test_paths_are_passed_as_process_arguments_without_shell_interpolation(string $suffix): void
    {
        $workspace = $this->workspace.'/'.$suffix;
        mkdir($workspace, 0700, true);
        $runner = new FakeWorktrunkRunner($workspace);
        $integration = new WorktrunkIntegration($workspace, $runner);

        $integration->prepare();

        self::assertSame(['wt', '--version'], $runner->commands[0]);
        self::assertSame($workspace, $runner->commands[1][2]);
        self::assertSame($workspace, $runner->commands[2][4]);
    }

    /** @return iterable<string, array{string}> */
    public static function hostilePaths(): iterable
    {
        yield 'unicode and spaces' => ['work tree ü'];
        yield 'quotes' => ["single'and\"double"];
        yield 'newline' => ["line\nbreak"];
        yield 'shell metacharacters' => ['$(touch owned); & | `echo bad`'];
    }

    public function test_it_refuses_a_candidate_rejected_by_the_real_validation_seam(): void
    {
        $runner = new FakeWorktrunkRunner($this->workspace);
        $runner->candidateResult = new ProcessResult(2, '', 'TOML parse error');
        $integration = new WorktrunkIntegration($this->workspace, $runner);

        try {
            $integration->prepare();
            self::fail('Expected candidate validation to fail.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::InvalidConfiguration, $exception->errorCode);
            self::assertSame('TOML parse error', $exception->context['stderr']);
        }
        self::assertFileDoesNotExist($this->workspace.'/.config/wt.toml');
    }

    public function test_it_refuses_a_candidate_that_does_not_resolve_to_the_exact_lifecycle(): void
    {
        $runner = new FakeWorktrunkRunner($this->workspace);
        $runner->candidateResult = new ProcessResult(0, '{"user":{"config":{"list":{"full":true}}}}');
        $integration = new WorktrunkIntegration($this->workspace, $runner);

        try {
            $integration->prepare();
            self::fail('Expected semantic candidate validation to fail.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::InvalidConfiguration, $exception->errorCode);
            self::assertStringContainsString('did not resolve', $exception->getMessage());
        }
    }

    public function test_it_refuses_malformed_existing_toml_without_rewriting_it(): void
    {
        mkdir($this->workspace.'/.config');
        file_put_contents($this->workspace.'/.config/wt.toml', "[broken\n");
        $runner = new FakeWorktrunkRunner($this->workspace);
        $runner->projectResult = new ProcessResult(2, '', 'invalid TOML syntax');
        $integration = new WorktrunkIntegration($this->workspace, $runner);

        try {
            $integration->prepare();
            self::fail('Expected malformed TOML to be refused.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::InvalidConfiguration, $exception->errorCode);
            self::assertSame('invalid TOML syntax', $exception->context['stderr']);
        }
        self::assertSame("[broken\n", file_get_contents($this->workspace.'/.config/wt.toml'));
    }

    public function test_it_refuses_invalid_validator_json_and_non_git_directories(): void
    {
        mkdir($this->workspace.'/.config');
        file_put_contents($this->workspace.'/.config/wt.toml', "# existing\n");
        $runner = new FakeWorktrunkRunner($this->workspace);
        $runner->projectResult = new ProcessResult(0, '{invalid');

        try {
            (new WorktrunkIntegration($this->workspace, $runner))->prepare();
            self::fail('Expected invalid validator JSON.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::InvalidConfiguration, $exception->errorCode);
        }

        $runner->projectResult = new ProcessResult(0, 'null');
        try {
            (new WorktrunkIntegration($this->workspace, $runner))->prepare();
            self::fail('Expected a non-object validator document.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::InvalidConfiguration, $exception->errorCode);
        }

        $runner->projectResult = new ProcessResult(0, '{"project":{"config":null,"path":null}}');
        try {
            (new WorktrunkIntegration($this->workspace, $runner))->prepare();
            self::fail('Expected a Git project path.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::IntegrationUnavailable, $exception->errorCode);
        }
    }

    public function test_status_reports_unsafe_invalid_and_unavailable_states(): void
    {
        mkdir($this->workspace.'/.config');
        $outside = $this->workspace.'/outside.toml';
        file_put_contents($outside, "# outside\n");
        symlink($outside, $this->workspace.'/.config/wt.toml');
        $runner = new FakeWorktrunkRunner($this->workspace);
        $integration = new WorktrunkIntegration($this->workspace, $runner);

        self::assertSame('unsafe', $integration->status()['configuration']);
        self::assertSame('retained', $integration->uninstall()->change);
        unlink($this->workspace.'/.config/wt.toml');
        file_put_contents($this->workspace.'/.config/wt.toml', "[broken\n");
        $runner->projectResult = new ProcessResult(2, '', 'invalid TOML');
        self::assertSame('invalid', $integration->status()['configuration']);

        unlink($this->workspace.'/.config/wt.toml');
        $runner->throwOnVersion = true;
        $status = $integration->status();
        $tool = $status['tool'];
        self::assertIsArray($tool);
        self::assertFalse($tool['available']);
        self::assertFalse($tool['supported']);
    }

    public function test_uninstall_removes_only_the_exact_harbour_owned_block(): void
    {
        $runner = new FakeWorktrunkRunner($this->workspace);
        $integration = new WorktrunkIntegration($this->workspace, $runner);
        $integration->install($integration->prepare());

        self::assertSame('removed', $integration->uninstall()->change);
        self::assertFileDoesNotExist($this->workspace.'/.config/wt.toml');

        file_put_contents($this->workspace.'/.config/wt.toml', "[pre-start]\ncustom = \"composer workspace:setup\"\n");
        self::assertSame('absent', $integration->uninstall()->change);
        self::assertFileExists($this->workspace.'/.config/wt.toml');
    }

    public function test_uninstall_retains_a_modified_harbour_owned_block(): void
    {
        $runner = new FakeWorktrunkRunner($this->workspace);
        $integration = new WorktrunkIntegration($this->workspace, $runner);
        $integration->install($integration->prepare());
        file_put_contents(
            $this->workspace.'/.config/wt.toml',
            str_replace('composer install', 'composer update', (string) file_get_contents($this->workspace.'/.config/wt.toml')),
        );

        self::assertSame('retained', $integration->uninstall()->change);
        self::assertFileExists($this->workspace.'/.config/wt.toml');
    }

    public function test_uninstall_removes_an_appended_block_and_preserves_unrelated_toml(): void
    {
        mkdir($this->workspace.'/.config');
        file_put_contents($this->workspace.'/.config/wt.toml', "# keep\n[list]\nfull = true\n");
        $runner = new FakeWorktrunkRunner($this->workspace);
        $integration = new WorktrunkIntegration($this->workspace, $runner);
        $integration->install($integration->prepare());

        self::assertSame('removed', $integration->uninstall()->change);
        self::assertSame("# keep\n[list]\nfull = true\n", file_get_contents($this->workspace.'/.config/wt.toml'));
    }

    public function test_scalar_teardown_hook_is_recognized_as_equivalent(): void
    {
        mkdir($this->workspace.'/.config');
        file_put_contents($this->workspace.'/.config/wt.toml', "# equivalent\n");
        $configuration = self::equivalentConfiguration();
        $configuration['pre-remove'] = WorktrunkIntegration::TEARDOWN;
        $integration = new WorktrunkIntegration($this->workspace, new FakeWorktrunkRunner($this->workspace, $configuration));

        self::assertSame('unchanged', $integration->prepare()->change);
    }

    public function test_filesystem_failures_leave_worktrunk_configuration_unclaimed(): void
    {
        $runner = new FakeWorktrunkRunner($this->workspace);
        $integration = new WorktrunkIntegration($this->workspace, $runner);
        $installation = $integration->prepare();
        file_put_contents($this->workspace.'/.config', 'directory blocker');

        try {
            $integration->install($installation);
            self::fail('A blocked configuration directory must fail installation.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::UnsafeOperation, $exception->errorCode);
        }

        unlink($this->workspace.'/.config');
        mkdir($this->workspace.'/.config');
        file_put_contents($this->workspace.'/.config/wt.toml', $installation->contents);
        chmod($this->workspace.'/.config/wt.toml', 0000);
        try {
            self::assertSame('retained', $integration->uninstall()->change);
            try {
                $integration->prepare();
                self::fail('Unreadable configuration must fail preparation.');
            } catch (HarbourException $exception) {
                self::assertSame(ErrorCode::UnsafeOperation, $exception->errorCode);
            }
        } finally {
            chmod($this->workspace.'/.config/wt.toml', 0600);
        }

        chmod($this->workspace.'/.config', 0500);
        try {
            $integration->uninstall();
            self::fail('An undeletable managed configuration must fail uninstallation.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::UnsafeOperation, $exception->errorCode);
        } finally {
            chmod($this->workspace.'/.config', 0700);
        }
    }

    public function test_temporary_file_failure_is_reported_before_candidate_validation(): void
    {
        $integration = new WorktrunkIntegration(
            $this->workspace,
            new FakeWorktrunkRunner($this->workspace),
            temporaryFile: static fn (): false => false,
        );

        try {
            $integration->prepare();
            self::fail('A missing temporary file must fail candidate validation.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::UnsafeOperation, $exception->errorCode);
        }
    }

    public function test_scalar_project_configuration_is_treated_as_empty(): void
    {
        mkdir($this->workspace.'/.config');
        file_put_contents($this->workspace.'/.config/wt.toml', "# existing\n");
        $runner = new FakeWorktrunkRunner($this->workspace);
        $runner->projectResult = new ProcessResult(0, (string) json_encode([
            'project' => ['config' => 'invalid', 'path' => $this->workspace.'/.config/wt.toml'],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('updated', (new WorktrunkIntegration($this->workspace, $runner))->prepare()->change);
    }

    /** @return array<string, mixed> */
    private static function equivalentConfiguration(): array
    {
        return [
            'pre-start' => [
                ['deps' => WorktrunkIntegration::COMPOSER_INSTALL],
                ['ready' => WorktrunkIntegration::SETUP],
            ],
            'pre-remove' => ['clean' => WorktrunkIntegration::TEARDOWN],
        ];
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
            is_dir($child) && ! is_link($child) ? $this->removeDirectory($child) : unlink($child);
        }
        rmdir($path);
    }
}

final class FakeWorktrunkRunner implements CommandRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    public ProcessResult $candidateResult;

    public ?ProcessResult $projectResult = null;

    public bool $throwOnVersion = false;

    /** @param array<string, mixed> $configuration */
    public function __construct(
        private readonly string $workspace,
        private readonly array $configuration = [],
        private readonly ?string $version = '0.76.0',
    ) {
        $this->candidateResult = new ProcessResult(0, (string) json_encode(['user' => ['config' => [
            'pre-start' => [
                ['harbour-composer-install' => WorktrunkIntegration::COMPOSER_INSTALL],
                ['harbour-setup' => WorktrunkIntegration::SETUP],
            ],
            'pre-remove' => ['harbour-teardown' => WorktrunkIntegration::TEARDOWN],
        ]]], JSON_THROW_ON_ERROR));
    }

    public function run(array $command, string $workingDirectory, array $environment = [], ?callable $output = null): ProcessResult
    {
        $this->commands[] = $command;
        if ($command === ['wt', '--version']) {
            if ($this->throwOnVersion) {
                throw new \RuntimeException('process unavailable');
            }

            return $this->version === null
                ? new ProcessResult(127, '', 'not found')
                : new ProcessResult(0, 'wt v'.$this->version);
        }
        if (in_array('--config', $command, true)) {
            return $this->candidateResult;
        }
        if ($this->projectResult instanceof ProcessResult) {
            return $this->projectResult;
        }

        $configuration = $this->configuration;
        $installed = $this->workspace.'/.config/wt.toml';
        if (is_file($installed) && str_contains((string) file_get_contents($installed), 'Harbour Worktrunk lifecycle integration')) {
            $configuration = [
                'pre-start' => [
                    ['harbour-composer-install' => WorktrunkIntegration::COMPOSER_INSTALL],
                    ['harbour-setup' => WorktrunkIntegration::SETUP],
                ],
                'pre-remove' => ['harbour-teardown' => WorktrunkIntegration::TEARDOWN],
            ];
        }

        return new ProcessResult(0, (string) json_encode([
            'project' => ['config' => $configuration, 'path' => $this->workspace.'/.config/wt.toml'],
        ], JSON_THROW_ON_ERROR));
    }
}
