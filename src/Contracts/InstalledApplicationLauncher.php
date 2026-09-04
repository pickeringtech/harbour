<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Contracts;

interface InstalledApplicationLauncher
{
    /** @param null|callable(string, string): void $output */
    public function launch(bool $vite = true, ?callable $output = null): void;
}
