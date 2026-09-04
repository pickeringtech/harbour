<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Integration;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Contracts\DatabaseLifecycleDriver;
use PickeringTech\Harbour\Contracts\WorkspaceStateRepository;
use PickeringTech\Harbour\Contracts\WorkspaceVariableResolver;
use PickeringTech\Harbour\Database\DatabaseConfiguration;
use PickeringTech\Harbour\Database\DatabaseManager;
use PickeringTech\Harbour\Docker\ComposeManager;
use PickeringTech\Harbour\Docker\DockerManager;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Process\ProcessResult;
use PickeringTech\Harbour\State\OwnedResource;
use PickeringTech\Harbour\Tests\TestCase;
use PickeringTech\Harbour\Variables\ResolvedVariable;
use PickeringTech\Harbour\Variables\VariableResolutionContext;
use PickeringTech\Harbour\WorkspaceManager;
use RuntimeException;
use stdClass;

final class WorkspaceLifecycleTest extends TestCase
{
    #[DataProvider('invalidConfigurations')]
    public function test_invalid_configuration_shapes_fail_with_stable_errors(string $case): void
    {
        $config = $this->application()->make(Repository::class);
        match ($case) {
            'ports-not-array' => $config->set('harbour.ports.allocations', 'invalid'),
            'port-definition' => $config->set('harbour.ports.allocations', ['APP_PORT' => []]),
            'port-bounds' => $config->set('harbour.ports.allocations', ['APP_PORT' => ['range' => ['low', 9000]]]),
            'variable-value' => $config->set('harbour.variables', ['BAD' => ['value' => []]]),
            'resolver-contract' => $config->set('harbour.resolvers', [stdClass::class]),
            'hook-argument' => $config->set('harbour.hooks.before_setup', [[PHP_BINARY, 123]]),
            'hook-shape' => $config->set('harbour.hooks.before_setup', [['command' => PHP_BINARY]]),
            'services-not-array' => $config->set('harbour.services', 'invalid'),
            'compose-not-array' => $config->set('harbour.compose', 'invalid'),
            'template-not-string' => $config->set('harbour.template', []),
            'template-missing' => $config->set('harbour.template', 'missing.env'),
            default => throw new LogicException("Unknown test case [{$case}]."),
        };

        try {
            $this->application()->make(WorkspaceManager::class)->setup();
            self::fail('Invalid configuration must fail setup.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::InvalidConfiguration, $exception->errorCode);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidConfigurations(): iterable
    {
        foreach ([
            'ports-not-array', 'port-definition', 'port-bounds', 'variable-value', 'resolver-contract',
            'hook-argument', 'hook-shape', 'services-not-array', 'compose-not-array',
            'template-not-string', 'template-missing',
        ] as $case) {
            yield $case => [$case];
        }
    }

    public function test_custom_variables_resolvers_process_environment_and_service_ports_have_deterministic_precedence(): void
    {
        $config = $this->application()->make(Repository::class);
        file_put_contents($this->workspaceDirectory.'/.env.harbour', "STATIC=\${STATIC}\nCUSTOM=\${CUSTOM}\nPROCESS_ONLY=\${PROCESS_ONLY}\nSHARED_PORT=\${SHARED_PORT}\n");
        $config->set('harbour.variables', [123 => 'ignored', 'STATIC' => 'configured']);
        $config->set('harbour.resolvers', [123, CoverageVariableResolver::class]);
        $config->set('harbour.services', [
            123 => [],
            'invalid' => 'ignored',
            'shared' => [
                'driver' => 'shared',
                'ports' => ['SHARED_PORT' => ['range' => [18500, 18520]]],
            ],
        ]);
        $config->set('harbour.compose', [123 => [], 'invalid' => 'ignored']);
        putenv('PROCESS_ONLY=from-process');

        try {
            $workspace = $this->application()->make(WorkspaceManager::class)->setup();
            self::assertSame('configured', $workspace->variables()->get('STATIC')?->value);
            self::assertSame('resolver', $workspace->variables()->get('CUSTOM')?->value);
            self::assertSame('from-process', $workspace->variables()->get('PROCESS_ONLY')?->value);
            self::assertGreaterThanOrEqual(18500, $workspace->ports()['SHARED_PORT']);
            $this->application()->make(WorkspaceManager::class)->teardown(true);
        } finally {
            putenv('PROCESS_ONLY');
        }
    }

    public function test_workspace_manager_orchestrates_and_reuses_docker_and_compose_resources(): void
    {
        file_put_contents($this->workspaceDirectory.'/compose.yml', "services: {}\n");
        $config = $this->application()->make(Repository::class);
        $config->set('harbour.services', [
            'search' => [
                'driver' => 'docker',
                'image' => 'example/search:latest',
                'ports' => ['SEARCH_PORT' => ['range' => [18600, 18620], 'container' => 7700]],
            ],
        ]);
        $config->set('harbour.compose', [
            'stack' => [
                'file' => 'compose.yml',
                'ports' => ['COMPOSE_PORT' => ['range' => [18700, 18720]]],
            ],
        ]);
        $runner = new WorkspaceManagerCommandRunner;
        $this->application()->instance(CommandRunner::class, $runner);
        $this->application()->forgetInstance(DockerManager::class);
        $this->application()->forgetInstance(ComposeManager::class);
        $this->application()->forgetInstance(WorkspaceManager::class);

        $manager = $this->application()->make(WorkspaceManager::class);
        $first = $manager->setup();
        $second = $manager->setup();

        self::assertCount(2, $first->state()->resources);
        self::assertCount(2, $second->state()->resources);
        self::assertArrayHasKey('SEARCH_PORT', $second->ports());
        self::assertArrayHasKey('COMPOSE_PORT', $second->ports());
        self::assertGreaterThanOrEqual(2, $runner->dockerStarts);
        self::assertGreaterThanOrEqual(2, $runner->composeStarts);

        $manager->teardown(true);
        self::assertNull($manager->current());
        self::assertSame(1, $runner->dockerRemovals);
        self::assertSame(1, $runner->composeDowns);
    }

    public function test_managed_compose_services_are_ready_before_database_creation(): void
    {
        file_put_contents($this->workspaceDirectory.'/compose.yml', "services: {}\n");
        file_put_contents($this->workspaceDirectory.'/.env.harbour', <<<'ENV'
        APP_PORT=${APP_PORT}
        DB_HOST=127.0.0.1
        DB_PORT=${DB_PORT}
        DB_USERNAME=harbour
        DB_PASSWORD=harbour
        ENV);
        $config = $this->application()->make(Repository::class);
        $config->set('harbour.installation.provider', 'compose');
        $config->set('harbour.ports.allocations.DB_PORT', ['range' => [18800, 18820]]);
        $config->set('harbour.compose', ['services' => ['file' => 'compose.yml']]);
        $config->set('harbour.database.enabled', true);
        $config->set('harbour.database.connection', 'sqlite');
        $config->set('harbour.database.migrate', false);

        $sequence = new WorkspaceSetupSequence;
        $this->application()->instance(CommandRunner::class, new OrderedWorkspaceRunner($sequence));
        $this->application()->instance(DatabaseManager::class, new DatabaseManager([new OrderedDatabaseDriver($sequence)]));
        $this->application()->forgetInstance(ComposeManager::class);
        $this->application()->forgetInstance(WorkspaceManager::class);

        $manager = $this->application()->make(WorkspaceManager::class);
        $manager->setup();

        self::assertSame(['compose', 'database'], $sequence->events);
        $manager->teardown(true);
    }

    public function test_sqlite_setup_preserves_laravels_host_array(): void
    {
        $config = $this->application()->make(Repository::class);
        $config->set('harbour.database.enabled', true);
        $config->set('harbour.database.connection', 'sqlite');
        $config->set('harbour.database.migrate', false);
        $config->set('database.connections.sqlite.host', ['localhost']);

        $sequence = new WorkspaceSetupSequence;
        $this->application()->instance(DatabaseManager::class, new DatabaseManager([new OrderedDatabaseDriver($sequence)]));
        $this->application()->forgetInstance(WorkspaceManager::class);

        $manager = $this->application()->make(WorkspaceManager::class);
        $manager->setup();

        self::assertSame(['localhost'], $config->get('database.connections.sqlite.host'));
        $manager->teardown(true);
    }

    public function test_ready_workspace_is_seeded_only_when_explicitly_requested(): void
    {
        $config = $this->application()->make(Repository::class);
        $config->set('harbour.database.enabled', true);
        $config->set('harbour.database.connection', 'sqlite');
        $config->set('harbour.database.migrate', false);
        $config->set('harbour.database.seed', true);
        $sequence = new WorkspaceSetupSequence;
        $this->application()->instance(DatabaseManager::class, new DatabaseManager([new OrderedDatabaseDriver($sequence)]));
        $kernel = $this->createMock(Kernel::class);
        $kernel->expects(self::exactly(2))->method('call')->with('db:seed', ['--force' => true])->willReturn(0);
        $this->application()->instance(Kernel::class, $kernel);

        $manager = $this->application()->make(WorkspaceManager::class);
        $manager->setup();
        $manager->setup();
        $manager->setup(seed: true);
        $manager->teardown(true);
    }

    public function test_lifecycle_hooks_run_through_the_command_runner(): void
    {
        $this->application()->make(Repository::class)->set('harbour.hooks.before_setup', [['hook-command', '--safe']]);
        $runner = new HookRecordingRunner;
        $this->application()->instance(CommandRunner::class, $runner);

        $manager = $this->application()->make(WorkspaceManager::class);
        $manager->setup();

        self::assertContains(['hook-command', '--safe'], $runner->commands);
        $manager->teardown(true);
    }

    public function test_fresh_setup_recreates_only_owned_sqlite_and_detects_missing_ownership(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The pdo_sqlite extension is required.');
        }
        $config = $this->application()->make(Repository::class);
        $config->set('harbour.database.enabled', true);
        $config->set('harbour.database.connection', 'sqlite');
        $config->set('harbour.database.migrate', false);
        $manager = $this->application()->make(WorkspaceManager::class);

        $first = $manager->setup();
        $fresh = $manager->setup(true);
        self::assertSame($first->identity()->id(), $fresh->identity()->id());
        $database = $this->workspaceDirectory.'/database/harbour.sqlite';
        self::assertFileExists($database);
        unlink($database);

        try {
            $manager->setup();
            self::fail('A missing owned database must not be silently recreated.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::DatabaseNotOwned, $exception->errorCode);
        } finally {
            $manager->teardown(true);
        }
    }

    public function test_corrupted_database_resource_name_is_rejected_and_unknown_resources_are_ignored_safely(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The pdo_sqlite extension is required.');
        }
        $config = $this->application()->make(Repository::class);
        $config->set('harbour.database.enabled', true);
        $config->set('harbour.database.connection', 'sqlite');
        $config->set('harbour.database.migrate', false);
        $manager = $this->application()->make(WorkspaceManager::class);
        $workspace = $manager->setup();
        $state = $workspace->state();
        $database = $state->resources[0];
        $repository = $this->application()->make(WorkspaceStateRepository::class);
        $repository->save($state->withResource(new OwnedResource(
            $database->id,
            $database->workspaceId,
            'database',
            'sqlite',
            [],
        )));

        try {
            $manager->setup();
            self::fail('A database without a persisted name must be rejected.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::StateCorrupted, $exception->errorCode);
        }

        $repository->save($state->withResource(new OwnedResource('custom-resource', $state->identity->id(), 'custom', 'custom', [])));
        $manager->teardown(true);
        self::assertNull($manager->current());
    }

    public function test_non_domain_setup_failures_are_wrapped_and_persisted(): void
    {
        $this->application()->make(Repository::class)->set('harbour.resolvers', [ExplodingVariableResolver::class]);
        $manager = $this->application()->make(WorkspaceManager::class);

        try {
            $manager->setup();
            self::fail('Resolver failure must abort setup.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::UnsafeOperation, $exception->errorCode);
            self::assertSame('failed', $this->application()->make(WorkspaceStateRepository::class)->load()?->status);
        } finally {
            $this->application()->make(Repository::class)->set('harbour.resolvers', []);
            $manager->teardown(true);
        }
    }

    #[DataProvider('databaseCommandFailures')]
    public function test_database_command_failures_are_reported(string $command): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The pdo_sqlite extension is required.');
        }
        $config = $this->application()->make(Repository::class);
        $config->set('harbour.database.enabled', true);
        $config->set('harbour.database.connection', 'sqlite');
        $config->set('harbour.database.migrate', $command === 'migrate');
        $config->set('harbour.database.seed', $command === 'db:seed');
        $kernel = $this->createMock(Kernel::class);
        $kernel->expects(self::once())->method('call')->with($command, ['--force' => true])->willReturn(9);
        $this->application()->instance(Kernel::class, $kernel);
        $manager = $this->application()->make(WorkspaceManager::class);

        try {
            $manager->setup();
            self::fail('A failing database command must abort setup.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::ProcessFailed, $exception->errorCode);
            self::assertSame(9, $exception->context['exit_code']);
        } finally {
            $manager->teardown(true);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function databaseCommandFailures(): iterable
    {
        yield 'migration' => ['migrate'];
        yield 'seeding' => ['db:seed'];
    }
}

final class CoverageVariableResolver implements WorkspaceVariableResolver
{
    public function resolve(VariableResolutionContext $context): iterable
    {
        yield new ResolvedVariable('CUSTOM', 'resolver', self::class);
    }
}

final class ExplodingVariableResolver implements WorkspaceVariableResolver
{
    public function resolve(VariableResolutionContext $context): iterable
    {
        throw new RuntimeException('resolver exploded');
    }
}

final class WorkspaceSetupSequence
{
    /** @var list<string> */
    public array $events = [];
}

final readonly class OrderedWorkspaceRunner implements CommandRunner
{
    public function __construct(private WorkspaceSetupSequence $sequence) {}

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function run(array $command, string $workingDirectory, array $environment = [], ?callable $output = null): ProcessResult
    {
        if (in_array('up', $command, true)) {
            $this->sequence->events[] = 'compose';
        }

        return new ProcessResult(0, '');
    }
}

final readonly class OrderedDatabaseDriver implements DatabaseLifecycleDriver
{
    public function __construct(private WorkspaceSetupSequence $sequence) {}

    public function supports(string $driver): bool
    {
        return $driver === 'sqlite';
    }

    public function create(OwnedResource $resource, string $workspacePath, DatabaseConfiguration $configuration): OwnedResource
    {
        $this->sequence->events[] = 'database';

        return $resource;
    }

    public function exists(OwnedResource $resource, DatabaseConfiguration $configuration): bool
    {
        return true;
    }

    public function destroy(OwnedResource $resource, DatabaseConfiguration $configuration, string $workspacePath): void {}
}

final class WorkspaceManagerCommandRunner implements CommandRunner
{
    /** @var array<string, string> */
    private array $labels = [];

    public int $dockerStarts = 0;

    public int $dockerRemovals = 0;

    public int $composeStarts = 0;

    public int $composeDowns = 0;

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function run(array $command, string $workingDirectory, array $environment = [], ?callable $output = null): ProcessResult
    {
        if (($command[0] ?? null) !== 'docker') {
            return new ProcessResult(0, '');
        }
        if (($command[1] ?? null) === 'create') {
            foreach ($command as $index => $argument) {
                if ($argument === '--label' && isset($command[$index + 1])) {
                    [$key, $value] = explode('=', $command[$index + 1], 2);
                    $this->labels[$key] = $value;
                }
            }

            return new ProcessResult(0, 'container-id');
        }
        if (($command[1] ?? null) === 'inspect' && in_array('--format', $command, true)) {
            return new ProcessResult(0, json_encode($this->labels, JSON_THROW_ON_ERROR));
        }
        if (($command[1] ?? null) === 'start') {
            $this->dockerStarts++;
        }
        if (($command[1] ?? null) === 'rm') {
            $this->dockerRemovals++;
        }
        if (($command[1] ?? null) === 'compose') {
            if (in_array('up', $command, true)) {
                $this->composeStarts++;
            }
            if (in_array('down', $command, true)) {
                $this->composeDowns++;
            }

            return new ProcessResult(0, '');
        }

        return new ProcessResult(0, 'ok');
    }
}

final class HookRecordingRunner implements CommandRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function run(array $command, string $workingDirectory, array $environment = [], ?callable $output = null): ProcessResult
    {
        $this->commands[] = $command;

        return new ProcessResult(0, '');
    }
}
