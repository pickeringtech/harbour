<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Variables;

use InvalidArgumentException;

final readonly class ResolvedVariable
{
    public function __construct(
        public string $name,
        public string $value,
        public string $source,
        public bool $secret = false,
        public bool $persist = true,
    ) {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException("Invalid environment variable name [{$name}].");
        }
    }

    public function isSensitive(): bool
    {
        return $this->secret || (bool) preg_match('/(?:APP_KEY|PASSWORD|PASSWD|TOKEN|SECRET|PRIVATE_KEY|API_KEY|CREDENTIAL)/i', $this->name);
    }
}
