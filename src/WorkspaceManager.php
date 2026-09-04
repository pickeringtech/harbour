<?php

declare(strict_types=1);

namespace PickeringTech\Harbour;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use PickeringTech\Harbour\Contracts\PortAllocationStrategy;
use PickeringTech\Harbour\Contracts\WorkspaceIdentityStrategy;
use PickeringTech\Harbour\Contracts\WorkspaceStateRepository;
use PickeringTech\Harbour\Database\DatabaseManager;
use PickeringTech\Harbour\Docker\ComposeManager;
use PickeringTech\Harbour\Docker\DockerManager;
use PickeringTech\Harbour\Environment\EnvironmentFile;
use PickeringTech\Harbour\Environment\EnvironmentManager;
use PickeringTech\Harbour\Environment\EnvironmentTemplate;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Hooks\LifecycleHookRunner;
use PickeringTech\Harbour\Identity\ContextIdentifier;
use PickeringTech\Harbour\Lifecycle\DatabaseLifecycle;
use PickeringTech\Harbour\Lifecycle\LifecycleHooks;
use PickeringTech\Harbour\Lifecycle\ManagedInfrastructure;
use PickeringTech\Harbour\Lifecycle\SetupSequence;
use PickeringTech\Harbour\Lifecycle\TeardownSequence;
use PickeringTech\Harbour\Lifecycle\VariablePipeline;
use PickeringTech\Harbour\Support\LifecycleLock;

final readonly class WorkspaceManager
{
    private VariablePipeline $variables;

    private DatabaseLifecycle $database;

    private SetupSequence $setupSequence;

    private TeardownSequence $teardownSequence;

    public function __construct(
        private string $workspacePath,
        private ConfigRepository $config,
        Container $container,
        Dispatcher $events,
        WorkspaceIdentityStrategy $identityStrategy,
        PortAllocationStrategy $portStrategy,
        private WorkspaceStateRepository $states,
        private EnvironmentManager $environment,
        private EnvironmentTemplate $templates,
        EnvironmentFile $environmentFile,
        ContextIdentifier $identifiers,
        DatabaseManager $databases,
        DockerManager $docker,
        ComposeManager $compose,
        LifecycleHookRunner $hooks,
        private LifecycleLock $lock,
    ) {
        $this->variables = new VariablePipeline(
            $workspacePath,
            $config,
            $container,
            $templates,
            $environmentFile,
            $identifiers,
        );
        $lifecycleHooks = new LifecycleHooks($workspacePath, $config, $hooks);
        $infrastructure = new ManagedInfrastructure($workspacePath, $config, $states, $docker, $compose);
        $this->database = new DatabaseLifecycle(
            $workspacePath,
            $config,
            $states,
            $environmentFile,
            $identifiers,
            $databases,
            $this->variables,
        );
        $this->setupSequence = new SetupSequence(
            $workspacePath,
            $config,
            $container,
            $events,
            $identityStrategy,
            $portStrategy,
            $states,
            $environment,
            $templates,
            $this->variables,
            $infrastructure,
            $this->database,
            $lifecycleHooks,
        );
        $this->teardownSequence = new TeardownSequence(
            $events,
            $portStrategy,
            $states,
            $environment,
            $this->variables,
            $infrastructure,
            $this->database,
            $lifecycleHooks,
        );
    }

    public function setup(bool $fresh = false): Workspace
    {
        $this->assertEnabled();

        return $this->lock->synchronized(function () use ($fresh): Workspace {
            if ($fresh && $this->states->load() !== null) {
                $this->teardownSequence->run(true);
            }

            return $this->setupSequence->run();
        });
    }

    public function teardown(bool $force = false): void
    {
        $this->assertEnabled();
        $this->lock->synchronized(fn () => $this->teardownSequence->run($force));
    }

    public function current(): ?Workspace
    {
        $state = $this->states->load();
        if ($state === null) {
            return null;
        }

        $recordedDatabase = $this->database->resource($state)?->metadata['database'] ?? null;

        return new Workspace($state, $this->variables->resolve(
            $state,
            is_string($recordedDatabase) ? $recordedDatabase : null,
        ));
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

            $recordedDatabase = $this->database->resource($state)?->metadata['database'] ?? null;
            $variables = $this->variables->resolve($state, is_string($recordedDatabase) ? $recordedDatabase : null, true);
            $rendered = $this->templates->render($this->variables->templateContents(), $variables->values());
            $state = $this->environment->render($state, $rendered);
            $this->states->save($state);

            return new Workspace($state, $variables);
        });
    }

    private function assertEnabled(): void
    {
        if (! (bool) $this->config->get('harbour.enabled', false)) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'Harbour is disabled. Set HARBOUR_ENABLED=true for an intentional local or CI run.');
        }
    }
}
