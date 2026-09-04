<?php

declare(strict_types=1);

namespace PickeringTech\Harbour;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use PickeringTech\Harbour\Contracts\PortAllocationStrategy;
use PickeringTech\Harbour\Contracts\WorkspaceIdentityStrategy;
use PickeringTech\Harbour\Contracts\WorkspaceStateRepository;
use PickeringTech\Harbour\Contracts\WorkspaceVariableResolver;
use PickeringTech\Harbour\Database\DatabaseConfiguration;
use PickeringTech\Harbour\Database\DatabaseManager;
use PickeringTech\Harbour\Docker\ComposeManager;
use PickeringTech\Harbour\Docker\DockerManager;
use PickeringTech\Harbour\Environment\EnvironmentFile;
use PickeringTech\Harbour\Environment\EnvironmentManager;
use PickeringTech\Harbour\Environment\EnvironmentTemplate;
use PickeringTech\Harbour\Events\WorkspaceSettingUp;
use PickeringTech\Harbour\Events\WorkspaceSetup;
use PickeringTech\Harbour\Events\WorkspaceTearingDown;
use PickeringTech\Harbour\Events\WorkspaceTornDown;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Hooks\LifecycleHookRunner;
use PickeringTech\Harbour\Identity\ContextIdentifier;
use PickeringTech\Harbour\Identity\WorkspaceContext;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;
use PickeringTech\Harbour\Ports\PortAllocation;
use PickeringTech\Harbour\Ports\PortRequirement;
use PickeringTech\Harbour\State\OwnedResource;
use PickeringTech\Harbour\State\WorkspaceState;
use PickeringTech\Harbour\Support\LifecycleLock;
use PickeringTech\Harbour\Variables\DefaultVariableResolver;
use PickeringTech\Harbour\Variables\ResolvedVariable;
use PickeringTech\Harbour\Variables\VariableBag;
use PickeringTech\Harbour\Variables\VariableResolutionContext;
use Throwable;

final readonly class WorkspaceManager
{
    public function __construct(
        private string $workspacePath,
        private ConfigRepository $config,
        private Container $container,
        private Dispatcher $events,
        private WorkspaceIdentityStrategy $identityStrategy,
        private PortAllocationStrategy $portStrategy,
        private WorkspaceStateRepository $states,
        private EnvironmentManager $environment,
        private EnvironmentTemplate $templates,
        private EnvironmentFile $environmentFile,
        private ContextIdentifier $identifiers,
        private DatabaseManager $databases,
        private DockerManager $docker,
        private ComposeManager $compose,
        private LifecycleHookRunner $hooks,
        private LifecycleLock $lock,
    ) {}

    public function setup(bool $fresh = false): Workspace
    {
        $this->assertEnabled();

        return $this->lock->synchronized(function () use ($fresh): Workspace {
            if ($fresh && $this->states->load() !== null) {
                $this->teardownWithinLock(true);
            }

            $state = $this->states->load();
            $identity = $state !== null
                ? $state->identity
                : $this->identityStrategy->resolve(new WorkspaceContext($this->workspacePath, $this->projectName()));
            $state ??= WorkspaceState::begin($identity, $this->workspacePath);
            $this->states->save($state);

            try {
                $this->events->dispatch(new WorkspaceSettingUp($identity));

                foreach ($this->portRequirements() as $requirement) {
                    $allocation = $this->portStrategy->allocate($identity, $this->workspacePath, $requirement);
                    $state = $state->withAllocation($allocation->name, $allocation->port);
                    $this->states->save($state);
                }

                $earlyVariables = $this->resolveVariables($state, null, true);
                $this->runHooks('before_setup', $earlyVariables);

                $state = $this->environment->prepare($state);
                $this->states->save($state);

                // Managed infrastructure must be listening before Harbour creates
                // logical databases or runs Laravel migrations against it.
                $state = $this->setupDockerResources($state);
                $infrastructureVariables = $this->resolveVariables($state, null, true);
                $state = $this->setupComposeResources($state, $infrastructureVariables);

                $databaseName = null;
                if ((bool) $this->config->get('harbour.database.enabled', true)) {
                    $database = $this->databaseResource($state);
                    $configuration = $this->databaseConfiguration(state: $state);

                    if ($database === null) {
                        $databaseName = $this->desiredDatabase($configuration, $identity);
                        $database = $this->databases->prepare($identity, $configuration, $databaseName);
                        $state = $state->withResource($database);
                        $this->states->save($state);
                        $database = $this->databases->create($database, $this->workspacePath, $configuration);
                        $state = $state->withResource($database);
                        $this->states->save($state);
                    } else {
                        $recordedDatabase = $database->metadata['database'] ?? null;
                        if (! is_string($recordedDatabase) || $recordedDatabase === '') {
                            throw new HarbourException(ErrorCode::StateCorrupted, 'Database resource has no valid database name.');
                        }
                        $databaseName = $recordedDatabase;
                        if (! $this->databases->exists($database, $configuration)) {
                            throw new HarbourException(ErrorCode::DatabaseNotOwned, 'Recorded workspace database is missing or has lost its ownership marker.');
                        }
                    }

                    $this->applyDatabaseToLaravel($configuration, $databaseName);
                }

                $variables = $this->resolveVariables($state, $databaseName, true);
                $state = $state->withVariables($variables->persistable());
                $this->states->save($state);
                $rendered = $this->templates->render($this->templateContents(), $variables->values());
                $state = $this->environment->render($state, $rendered);
                $this->states->save($state);

                $this->migrateAndSeed();
                $this->runHooks('after_setup', $variables);

                $state = $state->ready();
                $this->states->save($state);
                $workspace = new Workspace($state, $variables);
                $this->events->dispatch(new WorkspaceSetup($workspace));

                return $workspace;
            } catch (Throwable $exception) {
                $harbour = $exception instanceof HarbourException
                    ? $exception
                    : new HarbourException(ErrorCode::UnsafeOperation, 'Workspace setup failed.', [], $exception);
                $latestState = $this->states->load() ?? $state;
                $this->states->save($latestState->failed($harbour->errorCode->value));

                throw $harbour;
            }
        });
    }

    public function teardown(bool $force = false): void
    {
        $this->assertEnabled();
        $this->lock->synchronized(fn () => $this->teardownWithinLock($force));
    }

    public function current(): ?Workspace
    {
        $state = $this->states->load();

        if ($state === null) {
            return null;
        }

        $database = $this->databaseResource($state);
        $recordedName = $database?->metadata['database'] ?? null;
        $name = is_string($recordedName) ? $recordedName : null;

        return new Workspace($state, $this->resolveVariables($state, $name));
    }

    /** @return array{version: int, ok: true, workspace: array<string, mixed>} */
    public function status(): array
    {
        $workspace = $this->current();

        return [
            'version' => 1,
            'ok' => true,
            'workspace' => $workspace?->toArray() ?? ['status' => 'absent', 'path' => $this->workspacePath],
        ];
    }

    public function render(): Workspace
    {
        return $this->lock->synchronized(function (): Workspace {
            $state = $this->states->load();
            if ($state === null) {
                throw new HarbourException(ErrorCode::UnsafeOperation, 'Run workspace:setup before rendering the environment.');
            }

            $database = $this->databaseResource($state)?->metadata['database'] ?? null;
            $variables = $this->resolveVariables($state, is_string($database) ? $database : null, true);
            $rendered = $this->templates->render($this->templateContents(), $variables->values());
            $state = $this->environment->render($state, $rendered);
            $this->states->save($state);

            return new Workspace($state, $variables);
        });
    }

    private function teardownWithinLock(bool $force): void
    {
        $state = $this->states->load();

        if ($state === null) {
            return;
        }

        // Preflight restoration before destroying any owned resource. A known
        // environment conflict must never leave teardown half-complete.
        $this->environment->assertRestorable($state, $force);

        $workspace = new Workspace($state, $this->resolveVariables(
            $state,
            is_string($this->databaseResource($state)?->metadata['database'] ?? null)
                ? $this->databaseResource($state)?->metadata['database']
                : null,
        ));
        $this->events->dispatch(new WorkspaceTearingDown($workspace));
        $this->runHooks('before_teardown', $workspace->variables());
        $state = $state->tearingDown();
        $this->states->save($state);

        foreach (array_reverse($state->resources) as $resource) {
            match ($resource->type) {
                'compose_project' => $this->compose->destroy($resource, $this->workspacePath, $workspace->variables()->values()),
                'docker_container' => $this->docker->destroy($resource, $this->workspacePath),
                'database' => $this->databases->destroy($resource, $this->databaseConfiguration($resource->driver, $state), $this->workspacePath),
                default => null,
            };
        }

        $this->environment->restore($state, $force);

        foreach ($state->allocations as $name => $port) {
            $this->portStrategy->release(new PortAllocation($name, $port, $state->identity->id(), '127.0.0.1'));
        }
        $this->portStrategy->releaseWorkspace($state->identity);

        $this->runHooks('after_teardown', $workspace->variables());
        $this->states->delete();
        $this->environment->cleanupBackup();
        $this->events->dispatch(new WorkspaceTornDown($state->identity));
    }

    private function resolveVariables(WorkspaceState $state, ?string $database, bool $includeProcessEnvironment = false): VariableBag
    {
        $bag = new VariableBag;
        foreach ($state->variables as $name => $value) {
            $bag->put(new ResolvedVariable($name, $value, 'persisted_state'));
        }

        if ($includeProcessEnvironment) {
            $required = array_flip($this->templates->variables($this->templateContents()));
            $existingPath = $this->workspacePath.'/.env';
            if (is_file($existingPath) && ($contents = file_get_contents($existingPath)) !== false) {
                foreach ($this->environmentFile->parse($contents) as $name => $value) {
                    if (isset($required[$name])) {
                        $bag->put(new ResolvedVariable($name, $value, 'existing_environment', false, false));
                    }
                }
            }
            foreach (getenv() ?: [] as $name => $value) {
                if (isset($required[$name])) {
                    $bag->put(new ResolvedVariable($name, $value, 'process_environment', false, false));
                }
            }
        }

        $context = new VariableResolutionContext($state->identity, $this->workspacePath, $this->projectName(), $state->allocations, $database);
        foreach ((new DefaultVariableResolver($this->identifiers))->resolve($context) as $variable) {
            $bag->put($variable);
        }

        $configured = $this->config->get('harbour.variables', []);
        if (is_array($configured)) {
            foreach ($configured as $name => $definition) {
                if (! is_string($name)) {
                    continue;
                }
                if (is_array($definition)) {
                    $value = $definition['value'] ?? '';
                    if (! is_scalar($value)) {
                        throw new HarbourException(ErrorCode::InvalidConfiguration, "Configured variable [{$name}] must be scalar.");
                    }
                    $bag->put(new ResolvedVariable($name, (string) $value, 'project_configuration', ($definition['secret'] ?? false) === true));
                } elseif (is_scalar($definition)) {
                    $bag->put(new ResolvedVariable($name, (string) $definition, 'project_configuration'));
                }
            }
        }

        $resolvers = $this->config->get('harbour.resolvers', []);
        if (is_array($resolvers)) {
            foreach ($resolvers as $resolverClass) {
                if (! is_string($resolverClass)) {
                    continue;
                }
                $resolver = $this->container->make($resolverClass);
                if (! $resolver instanceof WorkspaceVariableResolver) {
                    throw new HarbourException(ErrorCode::InvalidConfiguration, "Variable resolver [{$resolverClass}] does not implement the contract.");
                }
                foreach ($resolver->resolve($context) as $variable) {
                    $bag->put($variable);
                }
            }
        }

        if ($includeProcessEnvironment
            && in_array('APP_KEY', $this->templates->variables($this->templateContents()), true)
            && $bag->get('APP_KEY') === null) {
            $bag->put(new ResolvedVariable(
                'APP_KEY',
                'base64:'.base64_encode(random_bytes(32)),
                'generated_workspace_secret',
                true,
                false,
            ));
        }

        return $bag;
    }

    /** @return list<PortRequirement> */
    private function portRequirements(): array
    {
        $requirements = [];
        $allocations = $this->config->get('harbour.ports.allocations', []);

        if (! is_array($allocations)) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, 'Port allocations must be an array.');
        }

        foreach ($allocations as $name => $definition) {
            if (! is_string($name) || ! is_array($definition) || ! is_array($definition['range'] ?? null) || count($definition['range']) !== 2) {
                throw new HarbourException(ErrorCode::InvalidConfiguration, 'Each port allocation requires a two-value range.');
            }
            [$minimum, $maximum] = $this->portRange($definition['range']);
            $requirements[] = new PortRequirement($name, $minimum, $maximum);
        }

        foreach ([$this->config->get('harbour.services', []), $this->config->get('harbour.compose', [])] as $services) {
            if (! is_array($services)) {
                continue;
            }
            foreach ($services as $service) {
                if (! is_array($service) || ! is_array($service['ports'] ?? null)) {
                    continue;
                }
                foreach ($service['ports'] as $name => $definition) {
                    if (! is_string($name) || ! is_array($definition) || ! is_array($definition['range'] ?? null) || count($definition['range']) !== 2) {
                        throw new HarbourException(ErrorCode::InvalidConfiguration, 'Service ports require a named two-value range.');
                    }
                    [$minimum, $maximum] = $this->portRange($definition['range']);
                    $requirements[] = new PortRequirement($name, $minimum, $maximum);
                }
            }
        }

        $unique = [];
        foreach ($requirements as $requirement) {
            $unique[$requirement->name] = $requirement;
        }

        return array_values($unique);
    }

    private function databaseConfiguration(?string $forceDriver = null, ?WorkspaceState $state = null): DatabaseConfiguration
    {
        $configured = $this->config->get('harbour.database.connection');
        if ($forceDriver !== null) {
            $connection = is_string($configured) && $configured !== '' ? $configured : $this->configuredString('database.default');
        } else {
            $templateValues = $this->environmentFile->parse($this->templateContents());
            $connection = is_string($configured) && $configured !== ''
                ? $configured
                : (($templateValues['DB_CONNECTION'] ?? '') !== '' && ! str_contains($templateValues['DB_CONNECTION'], '${')
                    ? $templateValues['DB_CONNECTION']
                    : $this->configuredString('database.default'));
        }
        $data = $this->config->get('database.connections.'.$connection, []);

        if (! is_array($data)) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, "Laravel database connection [{$connection}] is not configured.");
        }
        if ($forceDriver !== null) {
            $data['driver'] = $forceDriver;
        }

        if ($state !== null && $this->config->get('harbour.installation.provider') === 'compose') {
            $template = $this->environmentFile->parse($this->templateContents());
            foreach ([
                'host' => 'DB_HOST',
                'port' => 'DB_PORT',
                'username' => 'DB_USERNAME',
                'password' => 'DB_PASSWORD',
            ] as $key => $variable) {
                if (isset($template[$variable])) {
                    $data[$key] = $this->managedDatabaseValue($template[$variable], $state);
                }
            }
            if (($data['driver'] ?? null) === 'pgsql') {
                $data['harbour_admin_database'] = 'postgres';
            }
        }

        return DatabaseConfiguration::fromLaravel($this->stringKeyedArray($data));
    }

    private function desiredDatabase(DatabaseConfiguration $configuration, WorkspaceIdentity $identity): string
    {
        if ($configuration->driver === 'sqlite') {
            return $this->workspacePath.'/'.ltrim($this->configuredString('harbour.database.sqlite_path', 'database/harbour.sqlite'), '/');
        }

        return $this->identifiers->database($identity, $this->projectName());
    }

    private function applyDatabaseToLaravel(DatabaseConfiguration $configuration, string $database): void
    {
        $connection = $this->config->get('harbour.database.connection');
        if (! is_string($connection) || $connection === '') {
            $connection = $configuration->driver;
        }
        $this->config->set('database.default', $connection);
        $this->config->set('database.connections.'.$connection.'.host', $configuration->host);
        $this->config->set('database.connections.'.$connection.'.port', $configuration->port);
        $this->config->set('database.connections.'.$connection.'.username', $configuration->username);
        $this->config->set('database.connections.'.$connection.'.password', $configuration->password);
        $this->config->set('database.connections.'.$connection.'.database', $database);
    }

    private function managedDatabaseValue(string $value, WorkspaceState $state): string|int
    {
        if (preg_match('/\A\$\{([A-Z][A-Z0-9_]*)\}\z/', $value, $matches) === 1) {
            $variable = $matches[1];
            $allocation = $state->allocations[$variable] ?? null;
            if (! is_int($allocation)) {
                throw new HarbourException(ErrorCode::InvalidConfiguration, "Managed database variable [{$variable}] has no port allocation.");
            }

            return $allocation;
        }
        if (str_contains($value, '${')) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, 'Managed database connection values must be literal or allocated port variables.');
        }

        return $value;
    }

    private function migrateAndSeed(): void
    {
        if (! (bool) $this->config->get('harbour.database.enabled', true)) {
            return;
        }

        $kernel = $this->container->make(ConsoleKernel::class);
        if ((bool) $this->config->get('harbour.database.migrate', true)) {
            $exit = $kernel->call('migrate', ['--force' => true]);
            if ($exit !== 0) {
                throw new HarbourException(ErrorCode::ProcessFailed, 'Laravel migrations failed.', ['exit_code' => $exit]);
            }
        }
        if ((bool) $this->config->get('harbour.database.seed', false)) {
            $exit = $kernel->call('db:seed', ['--force' => true]);
            if ($exit !== 0) {
                throw new HarbourException(ErrorCode::ProcessFailed, 'Laravel database seeding failed.', ['exit_code' => $exit]);
            }
        }
    }

    private function runHooks(string $stage, VariableBag $variables): void
    {
        $commands = $this->config->get('harbour.hooks.'.$stage, []);
        if (is_array($commands)) {
            $normalized = [];
            foreach ($commands as $command) {
                if (is_string($command)) {
                    $normalized[] = $command;
                } elseif (is_array($command) && array_is_list($command)) {
                    $arguments = [];
                    foreach ($command as $argument) {
                        if (! is_string($argument)) {
                            throw new HarbourException(ErrorCode::InvalidConfiguration, "Invalid lifecycle hook in [{$stage}].");
                        }
                        $arguments[] = $argument;
                    }
                    $normalized[] = $arguments;
                } else {
                    throw new HarbourException(ErrorCode::InvalidConfiguration, "Invalid lifecycle hook in [{$stage}].");
                }
            }
            $this->hooks->run($stage, $normalized, $this->workspacePath, $variables->values());
        }
    }

    private function databaseResource(WorkspaceState $state): ?OwnedResource
    {
        foreach ($state->resources as $resource) {
            if ($resource->type === 'database') {
                return $resource;
            }
        }

        return null;
    }

    private function setupDockerResources(WorkspaceState $state): WorkspaceState
    {
        $services = $this->config->get('harbour.services', []);
        if (! is_array($services)) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, 'Harbour services must be an array.');
        }

        foreach ($services as $name => $configuration) {
            if (! is_string($name) || ! is_array($configuration) || ($configuration['driver'] ?? 'shared') !== 'docker') {
                continue;
            }
            $resource = $this->serviceResource($state, 'docker_container', $name);
            if ($resource === null) {
                $resource = $this->docker->prepare($state->identity, $name);
                $state = $state->withResource($resource);
                $this->states->save($state);
                $resource = $this->docker->create($resource, $this->workspacePath, $this->stringKeyedArray($configuration), $state->allocations);
                $state = $state->withResource($resource);
                $this->states->save($state);
            }
            $this->docker->start($resource, $this->workspacePath);
        }

        return $state;
    }

    private function setupComposeResources(WorkspaceState $state, VariableBag $variables): WorkspaceState
    {
        $projects = $this->config->get('harbour.compose', []);
        if (! is_array($projects)) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, 'Harbour Compose projects must be an array.');
        }

        foreach ($projects as $name => $configuration) {
            if (! is_string($name) || ! is_array($configuration)) {
                continue;
            }
            $resource = $this->serviceResource($state, 'compose_project', $name);
            if ($resource === null) {
                $resource = $this->compose->prepare($state->identity, $this->workspacePath, $name, $this->stringKeyedArray($configuration));
                $state = $state->withResource($resource);
                $this->states->save($state);
            }
            $this->compose->start($resource, $this->workspacePath, $variables->values());
        }

        return $state;
    }

    private function serviceResource(WorkspaceState $state, string $type, string $name): ?OwnedResource
    {
        foreach ($state->resources as $resource) {
            if ($resource->type === $type && ($resource->metadata['service'] ?? $resource->metadata['name'] ?? null) === $name) {
                return $resource;
            }
        }

        return null;
    }

    private function templateContents(): string
    {
        $configured = $this->configuredString('harbour.template', '.env.harbour');
        $path = str_starts_with($configured, '/') ? $configured : $this->workspacePath.'/'.$configured;
        $root = realpath($this->workspacePath);
        $resolved = realpath($path);

        if ($root === false || $resolved === false || ! is_file($resolved) || is_link($path)
            || ($resolved !== $root && ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR))) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, "Harbour environment template [{$path}] is missing or unsafe.");
        }
        $contents = file_get_contents($resolved);

        if ($contents === false) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, "Unable to read Harbour environment template [{$path}].");
        }

        return $contents;
    }

    private function projectName(): string
    {
        $configured = $this->config->get('harbour.project_name');

        return is_string($configured) && trim($configured) !== '' ? $configured : basename($this->workspacePath);
    }

    private function assertEnabled(): void
    {
        if (! (bool) $this->config->get('harbour.enabled', false)) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'Harbour is disabled. Set HARBOUR_ENABLED=true for an intentional local or CI run.');
        }
    }

    /**
     * @param  array<mixed>  $range
     * @return array{int, int}
     */
    private function portRange(array $range): array
    {
        $minimum = $range[0] ?? null;
        $maximum = $range[1] ?? null;
        if ((! is_int($minimum) && ! (is_string($minimum) && ctype_digit($minimum)))
            || (! is_int($maximum) && ! (is_string($maximum) && ctype_digit($maximum)))) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, 'Port range bounds must be integers.');
        }

        return [(int) $minimum, (int) $maximum];
    }

    /**
     * @param  array<mixed>  $value
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new HarbourException(ErrorCode::InvalidConfiguration, 'Configuration object keys must be strings.');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    private function configuredString(string $key, string $default = ''): string
    {
        $value = $this->config->get($key, $default);
        if (! is_string($value)) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, "Configuration [{$key}] must be a string.");
        }

        return $value;
    }
}
