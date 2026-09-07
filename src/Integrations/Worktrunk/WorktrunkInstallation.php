<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Integrations\Worktrunk;

final readonly class WorktrunkInstallation
{
    public function __construct(
        public string $contents,
        public string $change,
    ) {}
}
