<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Ports;

use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;

final readonly class PortRequirement
{
    public function __construct(
        public string $name,
        public int $minimum,
        public int $maximum,
        public string $host = '127.0.0.1',
    ) {
        if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, "Invalid port allocation name [{$name}].");
        }

        if ($minimum < 1024 || $maximum > 65535 || $minimum > $maximum) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, "Invalid port range for [{$name}].");
        }

        if (! in_array($host, ['127.0.0.1', '::1', 'localhost'], true)) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, "Port host [{$host}] is not a loopback address.");
        }
    }
}
