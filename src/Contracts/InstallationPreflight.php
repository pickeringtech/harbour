<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Contracts;

use PickeringTech\Harbour\Installation\InstallationSelection;

interface InstallationPreflight
{
    public function assertReady(InstallationSelection $selection): void;
}
