<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Contracts;

interface InstalledWorkspaceStarter
{
    public function start(): string;
}
