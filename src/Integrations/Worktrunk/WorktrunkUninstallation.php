<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Integrations\Worktrunk;

final readonly class WorktrunkUninstallation
{
    public function __construct(public string $change) {}
}
