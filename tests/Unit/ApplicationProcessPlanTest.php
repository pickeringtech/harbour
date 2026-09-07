<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;
use PickeringTech\Harbour\Process\ApplicationProcessPlan;
use PickeringTech\Harbour\Process\ForegroundApplicationLauncher;
use PickeringTech\Harbour\Process\ProcessResult;
use PickeringTech\Harbour\State\WorkspaceState;
use PickeringTech\Harbour\Tests\TestCase;
use PickeringTech\Harbour\Variables\VariableBag;
use PickeringTech\Harbour\Workspace;
use PickeringTech\Harbour\WorkspaceManager;

final class ApplicationProcessPlanTest extends TestCase
{
    /** @param list<string> $install */
    #[DataProvider('packageManagers')]
    public function test_it_builds_valid_commands_for_supported_node_package_managers(?string $lockfile, string $manager, array $install): void
    {
        file_put_contents($this->workspaceDirectory.'/artisan', "<?php\n");
        file_put_contents($this->workspaceDirectory.'/package.json', '{"scripts":{"dev":"vite"},"dependencies":{"laravel-vite-plugin":"^2"}}');
        if ($lockfile !== null) {
            file_put_contents($this->workspaceDirectory.'/'.$lockfile, 'lock');
        }
        $workspace = $this->application()->make(WorkspaceManager::class)->setup();
        $node = (new ApplicationProcessPlan($this->workspaceDirectory))->nodeCommands($workspace);

        self::assertIsArray($node);
        self::assertSame($manager, $node['command'][0]);
        self::assertSame($install, $node['install']);
    }

    /** @return iterable<string, array{?string, string, list<string>}> */
    public static function packageManagers(): iterable
    {
        yield 'npm' => [null, 'npm', ['npm', 'install']];
        yield 'Yarn' => ['yarn.lock', 'yarn', ['yarn', 'install']];
        yield 'pnpm' => ['pnpm-lock.yaml', 'pnpm', ['pnpm', 'install']];
        yield 'Bun text lock' => ['bun.lock', 'bun', ['bun', 'install']];
        yield 'Bun binary lock' => ['bun.lockb', 'bun', ['bun', 'install']];
    }

    public function test_it_launches_laravel_and_the_detected_vite_package_manager_on_allocated_ports(): void
    {
        file_put_contents($this->workspaceDirectory.'/artisan', "<?php\n");
        file_put_contents($this->workspaceDirectory.'/package.json', json_encode([
            'scripts' => ['dev' => 'vite'],
            'devDependencies' => ['vite' => '^7.0'],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->workspaceDirectory.'/pnpm-lock.yaml', "lockfileVersion: '9.0'\n");
        $workspace = $this->application()->make(WorkspaceManager::class)->setup();

        $plan = new ApplicationProcessPlan($this->workspaceDirectory);
        $processes = $plan->processes($workspace);

        self::assertSame('laravel', $processes[0]->name);
        self::assertContains('--port='.$workspace->ports()['APP_PORT'], $processes[0]->command);
        self::assertSame('vite', $processes[1]->name);
        self::assertSame('pnpm', $processes[1]->command[0]);
        self::assertContains((string) $workspace->ports()['VITE_PORT'], $processes[1]->command);
        self::assertSame(['pnpm', 'install'], $plan->nodeCommands($workspace)['install'] ?? null);
        self::assertFalse($plan->nodeDependenciesReady());
        mkdir($this->workspaceDirectory.'/node_modules/.bin', 0700, true);
        file_put_contents($this->workspaceDirectory.'/node_modules/.bin/vite', '');
        self::assertTrue($plan->nodeDependenciesReady());
    }

    public function test_it_skips_vite_when_the_project_has_no_vite_script_or_it_is_disabled(): void
    {
        file_put_contents($this->workspaceDirectory.'/artisan', "<?php\n");
        file_put_contents($this->workspaceDirectory.'/package.json', '{"scripts":{"test":"echo ok"}}');
        $workspace = $this->application()->make(WorkspaceManager::class)->setup();
        $plan = new ApplicationProcessPlan($this->workspaceDirectory);

        self::assertCount(1, $plan->processes($workspace));
        self::assertCount(1, $plan->processes($workspace, false));
        self::assertNull($plan->nodeCommands($workspace));
    }

    public function test_the_foreground_launcher_returns_when_the_attached_process_exits(): void
    {
        file_put_contents($this->workspaceDirectory.'/artisan', "<?php exit(0);\n");
        $workspace = $this->application()->make(WorkspaceManager::class)->setup();
        $plan = new ApplicationProcessPlan($this->workspaceDirectory);
        $launcher = new ForegroundApplicationLauncher($this->workspaceDirectory, $plan, new SuccessfulApplicationCommandRunner);

        self::assertSame(0, $launcher->launch($workspace, false));
    }

    public function test_the_launcher_reports_a_failed_node_dependency_install_before_starting_processes(): void
    {
        file_put_contents($this->workspaceDirectory.'/artisan', "<?php\n");
        file_put_contents($this->workspaceDirectory.'/package.json', '{"scripts":{"dev":"vite"},"devDependencies":{"vite":"^7"}}');
        $workspace = $this->application()->make(WorkspaceManager::class)->setup();
        $runner = new FailingApplicationCommandRunner;
        $seen = '';

        try {
            (new ForegroundApplicationLauncher(
                $this->workspaceDirectory,
                new ApplicationProcessPlan($this->workspaceDirectory),
                $runner,
            ))->launch($workspace, true, static function (string $name, string $buffer) use (&$seen): void {
                $seen .= $name.':'.$buffer;
            });
            self::fail('Expected Node dependency installation failure.');
        } catch (HarbourException $exception) {
            self::assertStringContainsString('Node dependencies', $exception->getMessage());
            self::assertSame('npm', $runner->command[0]);
            self::assertSame('node:install failed', $seen);
        }
    }

    public function test_it_rejects_missing_artisan_and_invalid_package_json(): void
    {
        $workspace = $this->application()->make(WorkspaceManager::class)->setup();
        $plan = new ApplicationProcessPlan($this->workspaceDirectory);

        try {
            $plan->processes($workspace);
            self::fail('Expected missing Artisan failure.');
        } catch (HarbourException $exception) {
            self::assertStringContainsString('Artisan', $exception->getMessage());
        }

        file_put_contents($this->workspaceDirectory.'/artisan', "<?php\n");
        file_put_contents($this->workspaceDirectory.'/package.json', '{invalid');
        try {
            $plan->processes($workspace);
            self::fail('Expected invalid package JSON failure.');
        } catch (HarbourException $exception) {
            self::assertStringContainsString('package.json', $exception->getMessage());
        }
    }

    public function test_it_rejects_missing_required_port_allocations_and_ignores_unsafe_or_irrelevant_node_manifests(): void
    {
        file_put_contents($this->workspaceDirectory.'/artisan', "<?php\n");
        $emptyWorkspace = new Workspace(
            WorkspaceState::begin(new WorkspaceIdentity('ws_test', 'test', str_repeat('a', 64), null), $this->workspaceDirectory),
            new VariableBag,
        );
        $plan = new ApplicationProcessPlan($this->workspaceDirectory);

        try {
            $plan->processes($emptyWorkspace);
            self::fail('Laravel launch requires APP_PORT.');
        } catch (HarbourException $exception) {
            self::assertStringContainsString('APP_PORT', $exception->getMessage());
        }

        $workspace = $this->application()->make(WorkspaceManager::class)->setup();
        self::assertNull($plan->nodeCommands($workspace));
        $outside = $this->workspaceDirectory.'/outside-package.json';
        file_put_contents($outside, '{"scripts":{"dev":"vite"},"dependencies":{"vite":"^7"}}');
        symlink($outside, $this->workspaceDirectory.'/package.json');
        try {
            $plan->nodeCommands($workspace);
            self::fail('A symlinked Node manifest must be rejected.');
        } catch (HarbourException $exception) {
            self::assertStringContainsString('symbolic link', $exception->getMessage());
        }
        unlink($this->workspaceDirectory.'/package.json');

        file_put_contents($this->workspaceDirectory.'/package.json', '{"scripts":{"dev":"echo no vite"},"dependencies":{"other":"^1"}}');
        self::assertNull($plan->nodeCommands($workspace));

        file_put_contents($this->workspaceDirectory.'/package.json', '{"scripts":{"dev":"vite"},"dependencies":{"vite":"^7"}}');
        $withoutVitePort = new Workspace(
            WorkspaceState::begin($workspace->identity(), $this->workspaceDirectory)->withAllocation('APP_PORT', 8000),
            new VariableBag,
        );
        try {
            $plan->nodeCommands($withoutVitePort);
            self::fail('Vite launch requires VITE_PORT.');
        } catch (HarbourException $exception) {
            self::assertStringContainsString('VITE_PORT', $exception->getMessage());
        }
    }

    public function test_foreground_process_output_is_named_and_remaining_processes_are_stopped(): void
    {
        file_put_contents($this->workspaceDirectory.'/artisan', "<?php fwrite(STDOUT, 'laravel ready'); sleep(10);\n");
        file_put_contents($this->workspaceDirectory.'/package.json', '{"scripts":{"dev":"exit 7"},"dependencies":{"vite":"^7"}}');
        mkdir($this->workspaceDirectory.'/node_modules/.bin', 0700, true);
        file_put_contents($this->workspaceDirectory.'/node_modules/.bin/vite', '');
        $workspace = $this->application()->make(WorkspaceManager::class)->setup();
        $seen = [];

        $exit = (new ForegroundApplicationLauncher(
            $this->workspaceDirectory,
            new ApplicationProcessPlan($this->workspaceDirectory),
            new SuccessfulApplicationCommandRunner,
        ))->launch($workspace, true, static function (string $name, string $buffer) use (&$seen): void {
            $seen[$name] = ($seen[$name] ?? '').$buffer;
        });

        self::assertSame(1, $exit);
        self::assertArrayHasKey('laravel', $seen);
    }

    public function test_node_installation_without_an_output_callback_still_launches(): void
    {
        file_put_contents($this->workspaceDirectory.'/artisan', "<?php exit(0);\n");
        file_put_contents($this->workspaceDirectory.'/package.json', '{"scripts":{"dev":"vite"},"dependencies":{"vite":"^7"}}');
        $workspace = $this->application()->make(WorkspaceManager::class)->setup();

        self::assertSame(0, (new ForegroundApplicationLauncher(
            $this->workspaceDirectory,
            new ApplicationProcessPlan($this->workspaceDirectory),
            new SuccessfulApplicationCommandRunner,
        ))->launch($workspace));
    }
}

final class SuccessfulApplicationCommandRunner implements CommandRunner
{
    public function run(array $command, string $workingDirectory, array $environment = [], ?callable $output = null): ProcessResult
    {
        return new ProcessResult(0, '');
    }
}

final class FailingApplicationCommandRunner implements CommandRunner
{
    /** @var list<string> */
    public array $command = [];

    public function run(array $command, string $workingDirectory, array $environment = [], ?callable $output = null): ProcessResult
    {
        $this->command = $command;
        if ($output !== null) {
            $output('out', 'install failed');
        }

        return new ProcessResult(1, '', 'install failed');
    }
}
