<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Contracts;

use PickeringTech\Harbour\Installation\InstallationRequirement;
use PickeringTech\Harbour\Installation\InstallationSelection;

interface InstallationPreflight
{
    /** @return list<InstallationRequirement> */
    public function requirements(InstallationSelection $selection): array;

    public function assertReady(InstallationSelection $selection): void;
}
