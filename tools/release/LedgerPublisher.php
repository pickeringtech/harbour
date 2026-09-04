<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Release;

interface LedgerPublisher
{
    public function append(Manifest $base, ReleaseEntry $entry): void;
}
