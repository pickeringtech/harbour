<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Database;

use PickeringTech\Harbour\Contracts\DatabaseLifecycleDriver;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;
use PickeringTech\Harbour\State\OwnedResource;

final readonly class DatabaseManager
{
    /** @param iterable<DatabaseLifecycleDriver> $drivers */
    public function __construct(private iterable $drivers) {}

    public function prepare(WorkspaceIdentity $workspace, DatabaseConfiguration $configuration, string $database): OwnedResource
    {
        return new OwnedResource('db_'.bin2hex(random_bytes(16)), $workspace->id(), 'database', $configuration->driver, [
            'database' => $database,
            'connection_fingerprint' => $configuration->fingerprint(),
            'ownership_token' => bin2hex(random_bytes(32)),
            'creation_pending' => true,
        ]);
    }

    public function create(OwnedResource $resource, string $workspacePath, DatabaseConfiguration $configuration): OwnedResource
    {
        $created = $this->driver($resource->driver)->create($resource, $workspacePath, $configuration);

        return new OwnedResource(
            $created->id,
            $created->workspaceId,
            $created->type,
            $created->driver,
            [...$created->metadata, 'creation_pending' => false],
            $created->createdByHarbour,
        );
    }

    public function creationPending(OwnedResource $resource): bool
    {
        return ($resource->metadata['creation_pending'] ?? false) === true;
    }

    public function exists(OwnedResource $resource, DatabaseConfiguration $configuration): bool
    {
        return $this->driver($resource->driver)->exists($resource, $configuration);
    }

    public function destroy(OwnedResource $resource, DatabaseConfiguration $configuration, string $workspacePath): void
    {
        $this->driver($resource->driver)->destroy($resource, $configuration, $workspacePath);
    }

    private function driver(string $name): DatabaseLifecycleDriver
    {
        foreach ($this->drivers as $driver) {
            if ($driver->supports($name)) {
                return $driver;
            }
        }

        throw new HarbourException(ErrorCode::InvalidConfiguration, "Unsupported database driver [{$name}].");
    }
}
