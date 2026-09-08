<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Integrations\Orca;

final readonly class OrcaInstallation
{
    public function __construct(
        public string $contents,
        public string $change,
    ) {}
}
