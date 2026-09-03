<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Contracts;

use PickeringTech\Harbour\Identity\WorkspaceContext;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;

interface WorkspaceIdentityStrategy
{
    public function resolve(WorkspaceContext $context): WorkspaceIdentity;
}
