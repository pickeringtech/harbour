<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Contracts;

use PickeringTech\Harbour\Installation\InstallationRequirement;

interface InstallationDependencyInstaller
{
    /**
     * @param  list<InstallationRequirement>  $requirements
     * @param  null|callable(string, string): void  $output
     */
    public function install(array $requirements, ?callable $output = null): void;
}
