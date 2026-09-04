<?php

declare(strict_types=1);

namespace PickeringTech\Harbour;

use PickeringTech\Harbour\Contracts\WorkspaceStateRepository;
use PickeringTech\Harbour\Environment\EnvironmentManager;
use PickeringTech\Harbour\Environment\EnvironmentTemplate;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Lifecycle\DatabaseLifecycle;
use PickeringTech\Harbour\Lifecycle\SetupSequence;
use PickeringTech\Harbour\Lifecycle\TeardownSequence;
use PickeringTech\Harbour\Lifecycle\VariablePipeline;
use PickeringTech\Harbour\Support\LifecycleLock;

final readonly class WorkspaceManager
{
    public function __construct(
        private string $workspacePath,
        private HarbourConfig $config,
        private WorkspaceStateRepository $states,
        private EnvironmentManager $environment,
        private EnvironmentTemplate $templates,
        private VariablePipeline $variables,
        private DatabaseLifecycle $database,
        private SetupSequence $setupSequence,
        private TeardownSequence $teardownSequence,
        private LifecycleLock $lock,
    ) {}

    /** @param null|callable(string, string): void $output */
    public function setup(bool $fresh = false, bool $force = false, bool $seed = false, ?callable $output = null): Workspace
    {
        $this->assertEnabled();

        return $this->lock->synchronized(function () use ($fresh, $force, $seed, $output): Workspace {
            $this->variables->beginOperation();
            if ($fresh && $this->states->load() !== null) {
                $this->teardownSequence->run(true);
            }

            return $this->setupSequence->run($force, $seed, $output);
        });
    }

    public function teardown(bool $force = false): void
    {
        $this->assertEnabled();
        $this->lock->synchronized(function () use ($force): void {
            $this->variables->beginOperation();
            $this->teardownSequence->run($force);
        });
    }

    public function current(): ?Workspace
    {
        $this->variables->beginOperation();
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

    public function render(bool $force = false): Workspace
    {
        return $this->lock->synchronized(function () use ($force): Workspace {
            $this->variables->beginOperation();
            $state = $this->states->load();
            if ($state === null) {
                throw new HarbourException(ErrorCode::UnsafeOperation, 'Run workspace:setup before rendering the environment.');
            }

            $this->environment->assertRenderable($state, $force);

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
        if (! $this->config->enabled) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'Harbour is disabled. Set HARBOUR_ENABLED=true for an intentional local or CI run.');
        }
    }
}
