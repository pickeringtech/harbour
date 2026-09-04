<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Docker;

use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Identity\ContextIdentifier;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;
use PickeringTech\Harbour\Process\ProcessFailure;
use PickeringTech\Harbour\State\OwnedResource;
use PickeringTech\Harbour\State\ResourceType;

final readonly class DockerManager
{
    public const MANAGED_LABEL = 'dev.harbour.managed';

    public const WORKSPACE_LABEL = 'dev.harbour.workspace';

    public const RESOURCE_LABEL = 'dev.harbour.resource';

    public function __construct(
        private CommandRunner $processes,
        private ContextIdentifier $identifiers,
    ) {}

    public function prepare(WorkspaceIdentity $workspace, string $name): OwnedResource
    {
        $resourceId = 'docker_'.bin2hex(random_bytes(16));

        return new OwnedResource($resourceId, $workspace->id(), ResourceType::DockerContainer, 'docker', [
            'service' => $name,
            'container_name' => $this->identifiers->docker($workspace, $name),
            'creation_pending' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, int>  $ports
     */
    public function create(OwnedResource $resource, string $workspacePath, array $configuration, array $ports): OwnedResource
    {
        $service = $resource->metadata['service'] ?? null;
        $name = is_string($service) ? $service : 'service';
        $image = $configuration['image'] ?? null;
        if (! is_string($image) || trim($image) === '' || preg_match('/[\r\n\0]/', $image)) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, "Docker service [{$name}] requires a safe image name.");
        }

        $resourceId = $resource->id;
        $container = $this->containerId($resource);
        $command = [
            'docker', 'create', '--name', $container,
            '--label', self::MANAGED_LABEL.'=true',
            '--label', self::WORKSPACE_LABEL.'='.$resource->workspaceId,
            '--label', self::RESOURCE_LABEL.'='.$resourceId,
        ];

        $portDefinitions = $configuration['ports'] ?? [];
        if (! is_array($portDefinitions)) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, "Docker service [{$name}] ports must be an array.");
        }
        foreach ($portDefinitions as $variable => $definition) {
            if (! is_string($variable) || ! isset($ports[$variable])) {
                throw new HarbourException(ErrorCode::InvalidConfiguration, 'A Docker port was not allocated.');
            }
            $containerPort = is_array($definition) ? ($definition['container'] ?? null) : $definition;
            if (! is_int($containerPort) || $containerPort < 1 || $containerPort > 65535) {
                throw new HarbourException(ErrorCode::InvalidConfiguration, "Docker service [{$name}] has an invalid container port.");
            }
            $command = [...$command, '--publish', '127.0.0.1:'.$ports[$variable].':'.$containerPort];
        }

        $environment = $configuration['environment'] ?? [];
        if (! is_array($environment)) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, "Docker service [{$name}] environment must be an array.");
        }
        foreach ($environment as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) || ! is_scalar($value)) {
                throw new HarbourException(ErrorCode::InvalidConfiguration, "Docker service [{$name}] has invalid environment configuration.");
            }
            $command = [...$command, '--env', $key.'='.(string) $value];
        }

        $command[] = '--';
        $command[] = $image;
        if (is_array($configuration['command'] ?? null)) {
            foreach ($configuration['command'] as $argument) {
                if (! is_string($argument)) {
                    throw new HarbourException(ErrorCode::InvalidConfiguration, "Docker service [{$name}] command must be a string list.");
                }
                $command[] = $argument;
            }
        }

        $result = $this->processes->run($command, $workspacePath);
        if (! $result->successful()) {
            throw new HarbourException(ErrorCode::ProcessFailed, "Unable to create Docker service [{$name}].", ProcessFailure::context($result, $environment));
        }

        return new OwnedResource($resourceId, $resource->workspaceId, ResourceType::DockerContainer, 'docker', [
            'service' => $name,
            'container_id' => $result->output,
            'container_name' => $container,
            'creation_pending' => false,
        ]);
    }

    public function start(OwnedResource $resource, string $workspacePath): void
    {
        $this->assertOwned($resource, $workspacePath);
        $result = $this->processes->run(['docker', 'start', $this->containerId($resource)], $workspacePath);
        if (! $result->successful()) {
            throw new HarbourException(ErrorCode::ProcessFailed, 'Unable to start a Harbour Docker resource.', ProcessFailure::context($result));
        }
    }

    public function destroy(OwnedResource $resource, string $workspacePath): void
    {
        if (! $this->exists($resource, $workspacePath)) {
            return;
        }
        $this->assertOwned($resource, $workspacePath);
        $result = $this->processes->run(['docker', 'rm', '--force', $this->containerId($resource)], $workspacePath);
        if (! $result->successful()) {
            throw new HarbourException(ErrorCode::ProcessFailed, 'Unable to remove a Harbour Docker resource.', ProcessFailure::context($result));
        }
    }

    public function assertOwned(OwnedResource $resource, string $workspacePath): void
    {
        if (! $resource->createdByHarbour || $resource->type !== ResourceType::DockerContainer) {
            throw new HarbourException(ErrorCode::DockerResourceNotOwned, 'Docker resource is not marked as Harbour-owned.');
        }
        $result = $this->processes->run([
            'docker', 'inspect', '--format',
            '{{json .Config.Labels}}', $this->containerId($resource),
        ], $workspacePath);
        $labels = $result->successful() ? json_decode($result->output, true) : null;
        if (! is_array($labels)
            || ($labels[self::MANAGED_LABEL] ?? null) !== 'true'
            || ($labels[self::WORKSPACE_LABEL] ?? null) !== $resource->workspaceId
            || ($labels[self::RESOURCE_LABEL] ?? null) !== $resource->id) {
            throw new HarbourException(ErrorCode::DockerResourceNotOwned, 'Docker labels do not match Harbour ownership state.');
        }
    }

    public function exists(OwnedResource $resource, string $workspacePath): bool
    {
        return $this->processes->run(['docker', 'inspect', $this->containerId($resource)], $workspacePath)->successful();
    }

    public function confirmCreated(OwnedResource $resource): OwnedResource
    {
        return $resource->created();
    }

    private function containerId(OwnedResource $resource): string
    {
        $id = $resource->metadata['container_id'] ?? $resource->metadata['container_name'] ?? null;
        if (! is_string($id) || $id === '' || preg_match('/[\r\n\0]/', $id)) {
            throw new HarbourException(ErrorCode::DockerResourceNotOwned, 'Docker resource has no safe container ID.');
        }

        return $id;
    }
}
