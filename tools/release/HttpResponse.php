<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Release;

final readonly class HttpResponse
{
    /** @param array<mixed> $data */
    public function __construct(
        public int $status,
        public array $data,
    ) {}
}
