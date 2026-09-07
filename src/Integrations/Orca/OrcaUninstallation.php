<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Integrations\Orca;

final readonly class OrcaUninstallation
{
    public function __construct(public string $change) {}
}
