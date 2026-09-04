<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Release;

final readonly class ReconciliationResult
{
    public function __construct(
        public ReleaseEntry $entry,
        public string $status,
        public string $detail,
        public bool $successful,
    ) {}
}
