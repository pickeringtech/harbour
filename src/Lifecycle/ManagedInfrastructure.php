<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Lifecycle;

use PickeringTech\Harbour\Contracts\WorkspaceStateRepository;
use PickeringTech\Harbour\Docker\ComposeManager;
use PickeringTech\Harbour\Docker\DockerManager;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\HarbourConfig;
use PickeringTech\Harbour\State\OwnedResource;
use PickeringTech\Harbour\State\ResourceType;
use PickeringTech\Harbour\State\WorkspaceState;
use PickeringTech\Harbour\Variables\VariableBag;

final readonly class ManagedInfrastructure
{
    public function __construct(
        private string $workspacePath,
        private HarbourConfig $config,
        private WorkspaceStateRepository $states,
        private DockerManager $docker,
        private ComposeManager $compose,
    ) {}

    public function setupDocker(WorkspaceState $state): WorkspaceState
    {
        $services = $this->config->services;

        foreach ($services as $name => $configuration) {
            if (($configuration['driver'] ?? 'shared') !== 'docker') {
                continue;
            }
            $resource = $this->serviceResource($state, ResourceType::DockerContainer, $name);
            if ($resource === null) {
                $resource = $this->docker->prepare($state->identity, $name);
                $state = $state->withResource($resource);
                $this->states->save($state);
                $resource = $this->docker->create($resource, $this->workspacePath, $configuration, $state->allocations);
                $state = $state->withResource($resource);
                $this->states->save($state);
            } elseif ($resource->creationPending()) {
                if ($this->docker->exists($resource, $this->workspacePath)) {
                    $this->docker->assertOwned($resource, $this->workspacePath);
                    $resource = $this->docker->confirmCreated($resource);
                } else {
                    $resource = $this->docker->create($resource, $this->workspacePath, $configuration, $state->allocations);
                }
                $state = $state->withResource($resource);
                $this->states->save($state);
            } elseif (! $this->docker->exists($resource, $this->workspacePath)) {
                throw new HarbourException(
                    ErrorCode::DockerResourceNotOwned,
                    'Recorded Harbour Docker container is missing. Run workspace:teardown --force then workspace:setup.',
                );
            }
            $this->docker->start($resource, $this->workspacePath);
        }

        return $state;
    }

    /** @param null|callable(string, string): void $output */
    public function setupCompose(WorkspaceState $state, VariableBag $variables, ?callable $output = null): WorkspaceState
    {
        $projects = $this->config->compose;

        foreach ($projects as $name => $configuration) {
            $resource = $this->serviceResource($state, ResourceType::ComposeProject, $name);
            if ($resource === null) {
                $resource = $this->compose->prepare($state->identity, $this->workspacePath, $name, $configuration);
                $state = $state->withResource($resource);
                $this->states->save($state);
            }
            $this->compose->start($resource, $this->workspacePath, $variables->values(), $output);
        }

        return $state;
    }

    public function destroyCompose(OwnedResource $resource, VariableBag $variables): void
    {
        $this->compose->destroy($resource, $this->workspacePath, $variables->values());
    }

    public function destroyDocker(OwnedResource $resource): void
    {
        $this->docker->destroy($resource, $this->workspacePath);
    }

    private function serviceResource(WorkspaceState $state, ResourceType $type, string $name): ?OwnedResource
    {
        foreach ($state->resources as $resource) {
            if ($resource->type === $type && ($resource->metadata['service'] ?? $resource->metadata['name'] ?? null) === $name) {
                return $resource;
            }
        }

        return null;
    }
}
