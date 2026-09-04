<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Lifecycle;

use Illuminate\Contracts\Events\Dispatcher;
use PickeringTech\Harbour\Contracts\PortAllocationStrategy;
use PickeringTech\Harbour\Contracts\WorkspaceStateRepository;
use PickeringTech\Harbour\Environment\EnvironmentManager;
use PickeringTech\Harbour\Events\WorkspaceTearingDown;
use PickeringTech\Harbour\Events\WorkspaceTornDown;
use PickeringTech\Harbour\Ports\PortAllocation;
use PickeringTech\Harbour\Workspace;

final readonly class TeardownSequence
{
    public function __construct(
        private Dispatcher $events,
        private PortAllocationStrategy $portStrategy,
        private WorkspaceStateRepository $states,
        private EnvironmentManager $environment,
        private VariablePipeline $variables,
        private ManagedInfrastructure $infrastructure,
        private DatabaseLifecycle $database,
        private LifecycleHooks $hooks,
    ) {}

    public function run(bool $force): void
    {
        $state = $this->states->load();
        if ($state === null) {
            return;
        }

        // Preflight restoration before destroying any owned resource. A known
        // environment conflict must never leave teardown half-complete.
        $this->environment->assertRestorable($state, $force);

        $recordedDatabase = $this->database->resource($state)?->metadata['database'] ?? null;
        $workspace = new Workspace($state, $this->variables->resolve(
            $state,
            is_string($recordedDatabase) ? $recordedDatabase : null,
        ));
        $this->events->dispatch(new WorkspaceTearingDown($workspace));
        $this->hooks->run('before_teardown', $workspace->variables());
        $state = $state->tearingDown();
        $this->states->save($state);

        foreach (array_reverse($state->resources) as $resource) {
            match ($resource->type) {
                'compose_project' => $this->infrastructure->destroyCompose($resource, $workspace->variables()),
                'docker_container' => $this->infrastructure->destroyDocker($resource),
                'database' => $this->database->destroy($resource, $state),
                default => null,
            };
        }

        $this->environment->restore($state, $force);

        foreach ($state->allocations as $name => $port) {
            $this->portStrategy->release(new PortAllocation($name, $port, $state->identity->id(), '127.0.0.1'));
        }
        $this->portStrategy->releaseWorkspace($state->identity);

        $this->hooks->run('after_teardown', $workspace->variables());
        $this->states->delete();
        $this->environment->cleanupBackup();
        $this->events->dispatch(new WorkspaceTornDown($state->identity));
    }
}
