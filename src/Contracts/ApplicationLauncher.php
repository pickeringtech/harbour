<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Contracts;

use PickeringTech\Harbour\Workspace;

interface ApplicationLauncher
{
    /** @param null|callable(string, string): void $output */
    public function launch(Workspace $workspace, bool $vite = true, ?callable $output = null): int;
}
