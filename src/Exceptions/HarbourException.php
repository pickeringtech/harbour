<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Exceptions;

use RuntimeException;
use Throwable;

final class HarbourException extends RuntimeException
{
    /** @param array<string, bool|int|float|string|null> $context */
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** @return array{code: string, message: string, context: array<string, bool|int|float|string|null>} */
    public function toArray(): array
    {
        return [
            'code' => $this->errorCode->value,
            'message' => $this->getMessage(),
            'context' => $this->context,
        ];
    }
}
