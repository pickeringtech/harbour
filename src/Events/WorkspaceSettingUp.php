<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Events;

use PickeringTech\Harbour\Identity\WorkspaceIdentity;

final readonly class WorkspaceSettingUp
{
    public function __construct(public WorkspaceIdentity $workspace) {}
}
