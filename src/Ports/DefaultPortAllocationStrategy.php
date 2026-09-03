<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Ports;

use PickeringTech\Harbour\Contracts\PortAllocationStrategy;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;

final readonly class DefaultPortAllocationStrategy implements PortAllocationStrategy
{
    public function __construct(private FilePortRegistry $registry) {}

    public function allocate(WorkspaceIdentity $workspace, string $workspacePath, PortRequirement $requirement): PortAllocation
    {
        return $this->registry->reserve($workspace->id(), $workspacePath, $requirement);
    }

    public function release(PortAllocation $allocation): bool
    {
        return $this->registry->release($allocation->workspaceId, $allocation->name, $allocation->port);
    }

    public function releaseWorkspace(WorkspaceIdentity $workspace): int
    {
        return $this->registry->releaseWorkspace($workspace->id());
    }
}
