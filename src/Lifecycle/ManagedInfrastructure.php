<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Lifecycle;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use PickeringTech\Harbour\Contracts\WorkspaceStateRepository;
use PickeringTech\Harbour\Docker\ComposeManager;
use PickeringTech\Harbour\Docker\DockerManager;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\State\OwnedResource;
use PickeringTech\Harbour\State\WorkspaceState;
use PickeringTech\Harbour\Variables\VariableBag;

final readonly class ManagedInfrastructure
{
    public function __construct(
        private string $workspacePath,
        private ConfigRepository $config,
        private WorkspaceStateRepository $states,
        private DockerManager $docker,
        private ComposeManager $compose,
    ) {}

    public function setupDocker(WorkspaceState $state): WorkspaceState
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
            } elseif ($this->docker->creationPending($resource)) {
                if ($this->docker->exists($resource, $this->workspacePath)) {
                    $this->docker->assertOwned($resource, $this->workspacePath);
                    $resource = $this->docker->confirmCreated($resource);
                } else {
                    $resource = $this->docker->create($resource, $this->workspacePath, $this->stringKeyedArray($configuration), $state->allocations);
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

    public function setupCompose(WorkspaceState $state, VariableBag $variables): WorkspaceState
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

    public function destroyCompose(OwnedResource $resource, VariableBag $variables): void
    {
        $this->compose->destroy($resource, $this->workspacePath, $variables->values());
    }

    public function destroyDocker(OwnedResource $resource): void
    {
        $this->docker->destroy($resource, $this->workspacePath);
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
}
