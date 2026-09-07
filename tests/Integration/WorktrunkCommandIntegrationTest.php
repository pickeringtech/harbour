<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Integration;

use Illuminate\Support\Facades\Artisan;
use PickeringTech\Harbour\Console\InstallCommand;
use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Integrations\Worktrunk\WorktrunkIntegration;
use PickeringTech\Harbour\Process\ProcessResult;
use PickeringTech\Harbour\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class WorktrunkCommandIntegrationTest extends TestCase
{
    public function test_interactive_no_invokes_no_worktrunk_command_or_configuration_change(): void
    {
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\"name\":\"acme/app\"}\n");
        $runner = new CommandWorktrunkRunner($this->workspaceDirectory);
        $this->application()->instance(WorktrunkIntegration::class, new WorktrunkIntegration($this->workspaceDirectory, $runner));
        $command = $this->application()->make(InstallCommand::class);
        $command->setLaravel($this->application());
        $tester = new CommandTester($command);
        $tester->setInputs(['Auto-detect from this project', 'yes', 'no', 'no']);

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertSame([], $runner->commands);
        self::assertFileDoesNotExist($this->workspaceDirectory.'/.config/wt.toml');
    }

    public function test_interactive_yes_offers_and_configures_worktrunk_as_full_lifecycle(): void
    {
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\"name\":\"acme/app\"}\n");
        $runner = new CommandWorktrunkRunner($this->workspaceDirectory);
        $this->application()->instance(WorktrunkIntegration::class, new WorktrunkIntegration($this->workspaceDirectory, $runner));
        $command = $this->application()->make(InstallCommand::class);
        $command->setLaravel($this->application());
        $tester = new CommandTester($command);
        $tester->setInputs(['Auto-detect from this project', 'yes', 'no', 'yes', 'Worktrunk (full lifecycle)']);

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertFileExists($this->workspaceDirectory.'/.config/wt.toml');
        self::assertStringContainsString('Worktree hooks', $tester->getDisplay());
        self::assertStringContainsString('worktrunk', $tester->getDisplay());
    }

    public function test_explicit_none_leaves_worktrunk_untouched_and_invokes_no_external_command(): void
    {
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\"name\":\"acme/app\"}\n");
        $runner = new CommandWorktrunkRunner($this->workspaceDirectory);
        $this->application()->instance(WorktrunkIntegration::class, new WorktrunkIntegration($this->workspaceDirectory, $runner));

        self::assertSame(0, Artisan::call('workspace:install', [
            '--detect' => true,
            '--worktree-hooks' => 'none',
            '--json' => true,
        ]));

        self::assertSame([], $runner->commands);
        self::assertFileDoesNotExist($this->workspaceDirectory.'/.config/wt.toml');
        self::assertStringContainsString('"worktree_hooks":[]', Artisan::output());
    }

    public function test_explicit_worktrunk_writes_validated_project_hooks_and_reports_them(): void
    {
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\"name\":\"acme/app\"}\n");
        $runner = new CommandWorktrunkRunner($this->workspaceDirectory);
        $this->application()->instance(WorktrunkIntegration::class, new WorktrunkIntegration($this->workspaceDirectory, $runner));

        self::assertSame(0, Artisan::call('workspace:install', [
            '--detect' => true,
            '--worktree-hooks' => 'worktrunk',
            '--json' => true,
        ]));

        self::assertFileExists($this->workspaceDirectory.'/.config/wt.toml');
        $output = Artisan::output();
        self::assertStringContainsString('"worktree_hooks":["worktrunk"]', $output);
        self::assertStringContainsString('".config/wt.toml"', $output);
        self::assertContains('--config', $runner->commands[2]);
    }

    public function test_invalid_selection_fails_before_files_or_external_commands_change(): void
    {
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\"name\":\"acme/app\"}\n");
        $runner = new CommandWorktrunkRunner($this->workspaceDirectory);
        $this->application()->instance(WorktrunkIntegration::class, new WorktrunkIntegration($this->workspaceDirectory, $runner));

        self::assertSame(1, Artisan::call('workspace:install', [
            '--detect' => true,
            '--worktree-hooks' => 'worktrunk,unknown',
            '--json' => true,
        ]));

        self::assertSame([], $runner->commands);
        self::assertFileDoesNotExist($this->workspaceDirectory.'/config/harbour.php');
        self::assertFileDoesNotExist($this->workspaceDirectory.'/.config/wt.toml');
        self::assertStringContainsString('HARBOUR_INVALID_INSTALL_SELECTION', Artisan::output());
    }

    public function test_status_exposes_version_capability_hooks_and_conflicts_in_json(): void
    {
        mkdir($this->workspaceDirectory.'/.config');
        file_put_contents($this->workspaceDirectory.'/.config/wt.toml', "[pre-start]\ncustom = \"npm install\"\n");
        $runner = new CommandWorktrunkRunner($this->workspaceDirectory, ['pre-start' => ['custom' => 'npm install']]);
        $this->application()->instance(WorktrunkIntegration::class, new WorktrunkIntegration($this->workspaceDirectory, $runner));

        self::assertSame(0, Artisan::call('workspace:status', ['--json' => true]));
        $status = Artisan::output();

        self::assertStringContainsString('"worktree_integrations":{"worktrunk"', $status);
        self::assertStringContainsString('"capability":"full_lifecycle"', $status);
        self::assertStringContainsString('"version":"0.76.0"', $status);
        self::assertStringContainsString('"configuration":"partial"', $status);
        $decoded = json_decode($status, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $integrations = $decoded['worktree_integrations'] ?? null;
        self::assertIsArray($integrations);
        $integration = $integrations['worktrunk'] ?? null;
        self::assertIsArray($integration);
        self::assertNotSame([], $integration['conflicts'] ?? []);
    }
}

final class CommandWorktrunkRunner implements CommandRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    /** @param array<string, mixed> $configuration */
    public function __construct(
        private readonly string $workspace,
        private readonly array $configuration = [],
    ) {}

    public function run(array $command, string $workingDirectory, array $environment = [], ?callable $output = null): ProcessResult
    {
        $this->commands[] = $command;
        if ($command === ['wt', '--version']) {
            return new ProcessResult(0, 'wt v0.76.0');
        }
        if (in_array('--config', $command, true)) {
            return new ProcessResult(0, (string) json_encode(['user' => ['config' => [
                'pre-start' => [
                    ['harbour-composer-install' => WorktrunkIntegration::COMPOSER_INSTALL],
                    ['harbour-setup' => WorktrunkIntegration::SETUP],
                ],
                'pre-remove' => ['harbour-teardown' => WorktrunkIntegration::TEARDOWN],
            ]]], JSON_THROW_ON_ERROR));
        }

        $configuration = $this->configuration;
        if (is_file($this->workspace.'/.config/wt.toml')
            && str_contains((string) file_get_contents($this->workspace.'/.config/wt.toml'), 'Harbour Worktrunk lifecycle integration')) {
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
