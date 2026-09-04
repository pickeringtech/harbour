<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Contracts;

interface InstalledWorkspaceStarter
{
    /** @param null|callable(string, string): void $output */
    public function start(?callable $output = null): string;
}
