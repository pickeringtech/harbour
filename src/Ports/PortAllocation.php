<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Ports;

final readonly class PortAllocation
{
    public function __construct(
        public string $name,
        public int $port,
        public string $workspaceId,
        public string $host,
    ) {}
}
