<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Integration;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Mockery;
use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Contracts\DatabaseLifecycleDriver;
use PickeringTech\Harbour\Contracts\PortAllocationStrategy;
use PickeringTech\Harbour\Database\DatabaseConfiguration;
use PickeringTech\Harbour\Database\DatabaseManager;
use PickeringTech\Harbour\Docker\DockerManager;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;
use PickeringTech\Harbour\Ports\PortAllocation;
use PickeringTech\Harbour\Ports\PortRequirement;
use PickeringTech\Harbour\Process\ProcessResult;
use PickeringTech\Harbour\State\OwnedResource;
use PickeringTech\Harbour\Tests\TestCase;
use PickeringTech\Harbour\WorkspaceManager;

final class FailureRecoveryTest extends TestCase
{
    public function test_teardown_recovers_allocations_and_environment_after_a_late_setup_failure(): void
    {
        $this->application()->make(Repository::class)->set('harbour.hooks.after_setup', [[PHP_BINARY, '-r', 'exit(23);']]);
        $manager = $this->application()->make(WorkspaceManager::class);

        try {
            $manager->setup();
            self::fail('Setup should have failed.');
        } catch (HarbourException $exception) {
            self::assertSame('PROCESS_FAILED', $exception->errorCode->value);
        }

        $state = $manager->current();
        self::assertNotNull($state);
        self::assertSame('failed', $state->state()->status);
        self::assertNotEmpty($state->ports());
        self::assertNotSame("ORIGINAL=yes\n", file_get_contents($this->workspaceDirectory.'/.env'));

        $manager->teardown(true);

        self::assertFileDoesNotExist($this->workspaceDirectory.'/.harbour.json');
        self::assertSame("ORIGINAL=yes\n", file_get_contents($this->workspaceDirectory.'/.env'));
    }

    public function test_teardown_releases_incremental_state_after_port_allocation_failure(): void
    {
        $ports = new FailingSecondPortStrategy;
        $this->application()->instance(PortAllocationStrategy::class, $ports);
        $manager = $this->application()->make(WorkspaceManager::class);

        try {
            $manager->setup();
            self::fail('Port allocation should have failed.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::PortAllocationFailed, $exception->errorCode);
        }

        self::assertSame(['APP_PORT' => 22100], $manager->current()?->ports());
        $manager->teardown(true);
        self::assertSame(['APP_PORT'], $ports->released);
        self::assertFileDoesNotExist($this->workspaceDirectory.'/.harbour.json');
    }

    public function test_teardown_recovers_a_prepared_database_after_creation_failure(): void
    {
        $driver = new InjectedDatabaseDriver(true);
        $this->enableInjectedDatabase($driver);
        $manager = $this->application()->make(WorkspaceManager::class);

        try {
            $manager->setup();
            self::fail('Database creation should have failed.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::DatabaseCreationFailed, $exception->errorCode);
        }

        self::assertSame('database', $manager->current()?->state()->resources[0]->type);
        $manager->teardown(true);
        self::assertSame(1, $driver->destroyed);
        self::assertSame("ORIGINAL=yes\n", file_get_contents($this->workspaceDirectory.'/.env'));
    }

    public function test_teardown_recovers_database_and_environment_after_render_failure(): void
    {
        file_put_contents($this->workspaceDirectory.'/.env.harbour', "MISSING=\${MISSING}\n");
        $driver = new InjectedDatabaseDriver(false);
        $this->enableInjectedDatabase($driver);
        $manager = $this->application()->make(WorkspaceManager::class);

        try {
            $manager->setup();
            self::fail('Environment rendering should have failed.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::UnresolvedVariable, $exception->errorCode);
        }

        $manager->teardown(true);
        self::assertSame(1, $driver->destroyed);
        self::assertSame("ORIGINAL=yes\n", file_get_contents($this->workspaceDirectory.'/.env'));
    }

    public function test_teardown_restores_environment_after_migration_failure(): void
    {
        $driver = new InjectedDatabaseDriver(false);
        $this->enableInjectedDatabase($driver);
        $this->application()->make(Repository::class)->set('harbour.database.migrate', true);
        $kernel = Mockery::mock(ConsoleKernel::class);
        $kernel->shouldReceive('call')->once()->with('migrate', ['--force' => true])->andReturn(1);
        $this->application()->instance(ConsoleKernel::class, $kernel);
        $manager = $this->application()->make(WorkspaceManager::class);

        try {
            $manager->setup();
            self::fail('Migration should have failed.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::ProcessFailed, $exception->errorCode);
        }

        self::assertNotSame("ORIGINAL=yes\n", file_get_contents($this->workspaceDirectory.'/.env'));
        $manager->teardown(true);
        self::assertSame(1, $driver->destroyed);
        self::assertSame("ORIGINAL=yes\n", file_get_contents($this->workspaceDirectory.'/.env'));
    }

    public function test_teardown_removes_a_created_container_after_startup_failure(): void
    {
        $config = $this->application()->make(Repository::class);
        $config->set('harbour.services', [
            'search' => ['driver' => 'docker', 'image' => 'alpine:3.22'],
        ]);
        $runner = new FailingDockerStartRunner;
        $this->application()->instance(CommandRunner::class, $runner);
        $this->application()->forgetInstance(DockerManager::class);
        $manager = $this->application()->make(WorkspaceManager::class);

        try {
            $manager->setup();
            self::fail('Docker startup should have failed.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::ProcessFailed, $exception->errorCode);
        }

        self::assertSame('docker_container', $manager->current()?->state()->resources[0]->type);
        $manager->teardown(true);
        self::assertTrue($runner->removed);
        self::assertSame("ORIGINAL=yes\n", file_get_contents($this->workspaceDirectory.'/.env'));
    }

    private function enableInjectedDatabase(InjectedDatabaseDriver $driver): void
    {
        $config = $this->application()->make(Repository::class);
        $config->set('harbour.database.enabled', true);
        $config->set('harbour.database.connection', 'sqlite');
        $config->set('harbour.database.migrate', false);
        $config->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);
        $this->application()->instance(DatabaseManager::class, new DatabaseManager([$driver]));
    }
}

final class FailingSecondPortStrategy implements PortAllocationStrategy
{
    public int $attempts = 0;

    /** @var list<string> */
    public array $released = [];

    public function allocate(WorkspaceIdentity $workspace, string $workspacePath, PortRequirement $requirement): PortAllocation
    {
        $this->attempts++;
        if ($this->attempts === 2) {
            throw new HarbourException(ErrorCode::PortAllocationFailed, 'Injected allocation failure.');
        }

        return new PortAllocation($requirement->name, 22100, $workspace->id(), '127.0.0.1');
    }

    public function release(PortAllocation $allocation): bool
    {
        $this->released[] = $allocation->name;

        return true;
    }

    public function releaseWorkspace(WorkspaceIdentity $workspace): int
    {
        return 0;
    }
}

final class InjectedDatabaseDriver implements DatabaseLifecycleDriver
{
    public int $destroyed = 0;

    public function __construct(private readonly bool $failCreation) {}

    public function supports(string $driver): bool
    {
        return $driver === 'sqlite';
    }

    public function create(OwnedResource $resource, string $workspacePath, DatabaseConfiguration $configuration): OwnedResource
    {
        if ($this->failCreation) {
            throw new HarbourException(ErrorCode::DatabaseCreationFailed, 'Injected database failure.');
        }

        return $resource;
    }

    public function exists(OwnedResource $resource, DatabaseConfiguration $configuration): bool
    {
        return true;
    }

    public function destroy(OwnedResource $resource, DatabaseConfiguration $configuration, string $workspacePath): void
    {
        $this->destroyed++;
    }
}

final class FailingDockerStartRunner implements CommandRunner
{
    /** @var array<string, string> */
    private array $labels = [];

    public bool $removed = false;

    public function run(array $command, string $workingDirectory, array $environment = []): ProcessResult
    {
        if (($command[1] ?? null) === 'create') {
            foreach ($command as $argument) {
                if (str_starts_with($argument, DockerManager::MANAGED_LABEL.'=')) {
                    $this->labels[DockerManager::MANAGED_LABEL] = substr($argument, strlen(DockerManager::MANAGED_LABEL) + 1);
                } elseif (str_starts_with($argument, DockerManager::WORKSPACE_LABEL.'=')) {
                    $this->labels[DockerManager::WORKSPACE_LABEL] = substr($argument, strlen(DockerManager::WORKSPACE_LABEL) + 1);
                } elseif (str_starts_with($argument, DockerManager::RESOURCE_LABEL.'=')) {
                    $this->labels[DockerManager::RESOURCE_LABEL] = substr($argument, strlen(DockerManager::RESOURCE_LABEL) + 1);
                }
            }

            return new ProcessResult(0, 'acceptance-container');
        }
        if (($command[1] ?? null) === 'start') {
            return new ProcessResult(19, '', 'Injected Docker startup failure.');
        }
        if (($command[1] ?? null) === 'inspect' && in_array('--format', $command, true)) {
            return new ProcessResult(0, (string) json_encode($this->labels));
        }
        if (($command[1] ?? null) === 'rm') {
            $this->removed = true;
        }

        return new ProcessResult(0, 'ok');
    }
}
