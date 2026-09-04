<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Lifecycle;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use PickeringTech\Harbour\Contracts\PortAllocationStrategy;
use PickeringTech\Harbour\Contracts\WorkspaceIdentityStrategy;
use PickeringTech\Harbour\Contracts\WorkspaceStateRepository;
use PickeringTech\Harbour\Environment\EnvironmentManager;
use PickeringTech\Harbour\Environment\EnvironmentTemplate;
use PickeringTech\Harbour\Events\WorkspaceSettingUp;
use PickeringTech\Harbour\Events\WorkspaceSetup;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\HarbourConfig;
use PickeringTech\Harbour\Hooks\LifecycleHookRunner;
use PickeringTech\Harbour\Identity\WorkspaceContext;
use PickeringTech\Harbour\Ports\PortRequirement;
use PickeringTech\Harbour\State\WorkspaceState;
use PickeringTech\Harbour\Workspace;
use Throwable;

final readonly class SetupSequence
{
    public function __construct(
        private string $workspacePath,
        private HarbourConfig $config,
        private Container $container,
        private Dispatcher $events,
        private WorkspaceIdentityStrategy $identityStrategy,
        private PortAllocationStrategy $portStrategy,
        private WorkspaceStateRepository $states,
        private EnvironmentManager $environment,
        private EnvironmentTemplate $templates,
        private VariablePipeline $variables,
        private ManagedInfrastructure $infrastructure,
        private DatabaseLifecycle $database,
        private LifecycleHookRunner $hooks,
    ) {}

    /** @param null|callable(string, string): void $output */
    public function run(bool $force = false, bool $seed = false, ?callable $output = null): Workspace
    {
        $state = $this->states->load();
        $wasReady = $state?->status === 'ready';
        if ($state !== null) {
            $this->environment->assertRenderable($state, $force);
        }
        $identity = $state !== null
            ? $state->identity
            : $this->identityStrategy->resolve(new WorkspaceContext($this->workspacePath, $this->variables->projectName()));
        $state ??= WorkspaceState::begin($identity, $this->workspacePath);
        if ($state->status !== 'preparing') {
            $state = $state->preparing();
        }
        $this->states->save($state);

        try {
            $this->events->dispatch(new WorkspaceSettingUp($identity));

            foreach ($this->portRequirements() as $requirement) {
                $allocation = $this->portStrategy->allocate($identity, $this->workspacePath, $requirement);
                $state = $state->withAllocation($allocation->name, $allocation->port);
                $this->states->save($state);
            }

            $earlyVariables = $this->variables->resolve($state, null, true);
            $this->hooks->run('before_setup', $earlyVariables);

            $state = $this->environment->prepare($state);
            $this->states->save($state);

            // Managed infrastructure must be listening before Harbour creates
            // logical databases or runs Laravel migrations against it.
            $state = $this->infrastructure->setupDocker($state);
            $infrastructureVariables = $this->variables->resolve($state, null, true);
            $state = $this->infrastructure->setupCompose($state, $infrastructureVariables, $output);

            [$state, $databaseName] = $this->database->setup($state);

            $resolved = $this->variables->resolve($state, $databaseName, true);
            $state = $state->withVariables($resolved->persistable());
            $this->states->save($state);
            $rendered = $this->templates->render($this->variables->templateContents(), $resolved->values());
            $state = $this->environment->render($state, $rendered);
            $this->states->save($state);

            $this->migrateAndSeed(! $wasReady || $seed);
            $this->hooks->run('after_setup', $resolved);

            $state = $state->ready();
            $this->states->save($state);
            $workspace = new Workspace($state, $resolved);
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
    }

    /** @return list<PortRequirement> */
    private function portRequirements(): array
    {
        $requirements = [];
        $allocations = $this->config->portAllocations;

        foreach ($allocations as $name => $definition) {
            [$minimum, $maximum] = $definition['range'];
            $requirements[] = new PortRequirement($name, $minimum, $maximum);
        }

        foreach ([$this->config->services, $this->config->compose] as $services) {
            foreach ($services as $service) {
                if (! isset($service['ports']) || ! is_array($service['ports'])) {
                    continue;
                }
                foreach ($service['ports'] as $name => $definition) {
                    /** @var array{range: array{int, int}} $definition */
                    [$minimum, $maximum] = $definition['range'];
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

    private function migrateAndSeed(bool $shouldSeed): void
    {
        if (! $this->config->databaseEnabled) {
            return;
        }

        $kernel = $this->container->make(ConsoleKernel::class);
        if ($this->config->databaseMigrate) {
            $exit = $kernel->call('migrate', ['--force' => true]);
            if ($exit !== 0) {
                throw new HarbourException(ErrorCode::ProcessFailed, 'Laravel migrations failed.', ['exit_code' => $exit]);
            }
        }
        if ($shouldSeed && $this->config->databaseSeed) {
            $exit = $kernel->call('db:seed', ['--force' => true]);
            if ($exit !== 0) {
                throw new HarbourException(ErrorCode::ProcessFailed, 'Laravel database seeding failed.', ['exit_code' => $exit]);
            }
        }
    }
}
