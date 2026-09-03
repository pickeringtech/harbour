<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Contracts;

use PickeringTech\Harbour\Identity\WorkspaceIdentity;
use PickeringTech\Harbour\Ports\PortAllocation;
use PickeringTech\Harbour\Ports\PortRequirement;

interface PortAllocationStrategy
{
    public function allocate(WorkspaceIdentity $workspace, string $workspacePath, PortRequirement $requirement): PortAllocation;

    public function release(PortAllocation $allocation): bool;

    public function releaseWorkspace(WorkspaceIdentity $workspace): int;
}
