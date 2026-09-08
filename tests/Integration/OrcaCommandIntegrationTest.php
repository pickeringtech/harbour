<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Integration;

use Illuminate\Support\Facades\Artisan;
use PickeringTech\Harbour\Console\InstallCommand;
use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Integrations\Orca\OrcaIntegration;
use PickeringTech\Harbour\Process\ProcessResult;
use PickeringTech\Harbour\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class OrcaCommandIntegrationTest extends TestCase
{
    public function test_interactive_no_invokes_no_orca_command_or_configuration_change(): void
    {
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\"name\":\"acme/app\"}\n");
        $runner = new CommandOrcaRunner;
        $this->application()->instance(OrcaIntegration::class, new OrcaIntegration($this->workspaceDirectory, $runner));
        $command = $this->application()->make(InstallCommand::class);
        $command->setLaravel($this->application());
        $tester = new CommandTester($command);
        $tester->setInputs(['Auto-detect from this project', 'yes', 'no', 'no']);

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertSame([], $runner->commands);
        self::assertFileDoesNotExist($this->workspaceDirectory.'/orca.yaml');
    }

    public function test_interactive_yes_offers_and_configures_orca_as_full_lifecycle(): void
    {
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\"name\":\"acme/app\"}\n");
        $runner = new CommandOrcaRunner;
        $this->application()->instance(OrcaIntegration::class, new OrcaIntegration($this->workspaceDirectory, $runner));
        $command = $this->application()->make(InstallCommand::class);
        $command->setLaravel($this->application());
        $tester = new CommandTester($command);
        $tester->setInputs(['Auto-detect from this project', 'yes', 'no', 'yes', 'Orca IDE (requires verified blocking archive)']);

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertFileExists($this->workspaceDirectory.'/orca.yaml');
        self::assertStringContainsString('Worktree hooks', $tester->getDisplay());
        self::assertStringContainsString('orca', $tester->getDisplay());
    }

    public function test_explicit_none_leaves_orca_untouched_and_invokes_no_external_command(): void
    {
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\"name\":\"acme/app\"}\n");
        $runner = new CommandOrcaRunner;
        $this->application()->instance(OrcaIntegration::class, new OrcaIntegration($this->workspaceDirectory, $runner));

        self::assertSame(0, Artisan::call('workspace:install', [
            '--detect' => true,
            '--worktree-hooks' => 'none',
            '--json' => true,
        ]));

        self::assertSame([], $runner->commands);
        self::assertFileDoesNotExist($this->workspaceDirectory.'/orca.yaml');
        self::assertStringContainsString('"worktree_hooks":[]', Artisan::output());
    }

    public function test_explicit_orca_writes_project_hooks_and_reports_them(): void
    {
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\"name\":\"acme/app\"}\n");
        $runner = new CommandOrcaRunner;
        $this->application()->instance(OrcaIntegration::class, new OrcaIntegration($this->workspaceDirectory, $runner));

        self::assertSame(0, Artisan::call('workspace:install', [
            '--detect' => true,
            '--worktree-hooks' => 'orca',
            '--json' => true,
        ]));

        self::assertFileExists($this->workspaceDirectory.'/orca.yaml');
        $output = Artisan::output();
        self::assertStringContainsString('"worktree_hooks":["orca"]', $output);
        self::assertStringContainsString('"orca.yaml"', $output);
        self::assertSame('agent-context', $runner->commands[0][1]);
    }

    public function test_current_orca_run_hooks_capability_fails_closed_before_project_changes(): void
    {
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\"name\":\"acme/app\"}\n");
        $runner = new CommandOrcaRunner(blockingArchiveCapability: false);
        $this->application()->instance(OrcaIntegration::class, new OrcaIntegration($this->workspaceDirectory, $runner));

        self::assertSame(1, Artisan::call('workspace:install', [
            '--detect' => true,
            '--worktree-hooks' => 'orca',
            '--json' => true,
        ]));

        self::assertFileDoesNotExist($this->workspaceDirectory.'/orca.yaml');
        self::assertFileDoesNotExist($this->workspaceDirectory.'/config/harbour.php');
        $output = Artisan::output();
        self::assertStringContainsString('HARBOUR_INTEGRATION_UNAVAILABLE', $output);
        self::assertStringContainsString('stablyai/orca#19334', $output);
    }

    public function test_existing_equivalent_hooks_are_reported_unchanged(): void
    {
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\"name\":\"acme/app\"}\n");
        $yaml = "scripts:\n  setup: ".OrcaIntegration::SETUP."\n  archive: ".OrcaIntegration::ARCHIVE."\n";
        file_put_contents($this->workspaceDirectory.'/orca.yaml', $yaml);
        $this->application()->instance(OrcaIntegration::class, new OrcaIntegration($this->workspaceDirectory, new CommandOrcaRunner));

        self::assertSame(0, Artisan::call('workspace:install', [
            '--detect' => true,
            '--worktree-hooks' => 'orca',
            '--json' => true,
        ]));

        self::assertSame($yaml, file_get_contents($this->workspaceDirectory.'/orca.yaml'));
        self::assertStringContainsString('"orca.yaml"],"conflicts"', Artisan::output());
    }

    public function test_conflicting_hooks_fail_before_any_project_mutation(): void
    {
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\"name\":\"acme/app\"}\n");
        $yaml = "scripts:\n  setup: npm install\n";
        file_put_contents($this->workspaceDirectory.'/orca.yaml', $yaml);
        $this->application()->instance(OrcaIntegration::class, new OrcaIntegration($this->workspaceDirectory, new CommandOrcaRunner));

        self::assertSame(1, Artisan::call('workspace:install', [
            '--detect' => true,
            '--worktree-hooks' => 'orca',
            '--json' => true,
        ]));

        self::assertSame($yaml, file_get_contents($this->workspaceDirectory.'/orca.yaml'));
        self::assertFileDoesNotExist($this->workspaceDirectory.'/config/harbour.php');
        $output = Artisan::output();
        self::assertStringContainsString('HARBOUR_INTEGRATION_CONFLICT', $output);
        self::assertStringContainsString('"manual_merge":"scripts:', $output);
    }

    public function test_status_exposes_orca_states_capabilities_and_removal_policy_in_json_and_human_output(): void
    {
        file_put_contents($this->workspaceDirectory.'/orca.yaml', "scripts:\n  setup: npm install\n");
        $this->application()->instance(OrcaIntegration::class, new OrcaIntegration($this->workspaceDirectory, new CommandOrcaRunner));

        self::assertSame(0, Artisan::call('workspace:status', ['--json' => true]));
        $status = Artisan::output();
        self::assertStringContainsString('"orca":{"state":"conflicting"', $status);
        self::assertStringContainsString('"setup_policy":true', $status);
        self::assertStringContainsString('"blocking_archive":true', $status);
        self::assertStringContainsString('"removal_command":"orca worktree rm --worktree', $status);

        self::assertSame(0, Artisan::call('workspace:status'));
        $human = Artisan::output();
        self::assertStringContainsString('Orca IDE', $human);
        self::assertStringContainsString('conflicting', $human);
    }

    public function test_invalid_selection_fails_before_orca_is_invoked(): void
    {
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\"name\":\"acme/app\"}\n");
        $runner = new CommandOrcaRunner;
        $this->application()->instance(OrcaIntegration::class, new OrcaIntegration($this->workspaceDirectory, $runner));

        self::assertSame(1, Artisan::call('workspace:install', [
            '--detect' => true,
            '--worktree-hooks' => 'orca,unknown',
            '--json' => true,
        ]));

        self::assertSame([], $runner->commands);
        self::assertStringContainsString('Choose one of: orca, worktrunk, none', Artisan::output());
    }
}

final class CommandOrcaRunner implements CommandRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    public function __construct(private readonly bool $blockingArchiveCapability = true) {}

    public function run(array $command, string $workingDirectory, array $environment = [], ?callable $output = null): ProcessResult
    {
        $this->commands[] = $command;
        if (($command[1] ?? null) === 'agent-context') {
            return new ProcessResult(0, (string) json_encode(['commands' => [
                ['command' => 'worktree create', 'flags' => ['setup']],
                ['command' => 'worktree rm', 'flags' => ['run-hooks']],
            ]], JSON_THROW_ON_ERROR));
        }

        return new ProcessResult(0, (string) json_encode([
            'result' => ['runtime' => [
                'appVersion' => '1.4.197',
                'capabilities' => $this->blockingArchiveCapability ? [OrcaIntegration::BLOCKING_ARCHIVE_CAPABILITY] : [],
            ]],
        ], JSON_THROW_ON_ERROR));
    }
}
