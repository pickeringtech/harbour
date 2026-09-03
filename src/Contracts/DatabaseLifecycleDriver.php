<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Contracts;

use PickeringTech\Harbour\Database\DatabaseConfiguration;
use PickeringTech\Harbour\State\OwnedResource;

interface DatabaseLifecycleDriver
{
    public function supports(string $driver): bool;

    public function create(
        OwnedResource $resource,
        string $workspacePath,
        DatabaseConfiguration $configuration,
    ): OwnedResource;

    public function exists(OwnedResource $resource, DatabaseConfiguration $configuration): bool;

    public function destroy(OwnedResource $resource, DatabaseConfiguration $configuration, string $workspacePath): void;
}
