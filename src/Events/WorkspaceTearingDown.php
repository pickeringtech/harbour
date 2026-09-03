<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Events;

use PickeringTech\Harbour\Workspace;

final readonly class WorkspaceTearingDown
{
    public function __construct(public Workspace $workspace) {}
}
