<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Release;

interface PackagistClient
{
    public function resolvesTo(ReleaseEntry $entry): bool;
}
