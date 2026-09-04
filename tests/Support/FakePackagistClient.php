<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Support;

use PickeringTech\Harbour\Release\PackagistClient;
use PickeringTech\Harbour\Release\ReleaseEntry;

final class FakePackagistClient implements PackagistClient
{
    /** @var array<string, list<bool>> */
    public array $responses = [];

    /** @var list<string> */
    public array $calls = [];

    public function resolvesTo(ReleaseEntry $entry): bool
    {
        $this->calls[] = $entry->version;
        $responses = $this->responses[$entry->version] ?? [true];
        $response = array_shift($responses) ?? true;
        $this->responses[$entry->version] = $responses;

        return $response;
    }
}
