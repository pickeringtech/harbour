<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Process;

final readonly class ApplicationProcess
{
    /** @param list<string> $command */
    public function __construct(
        public string $name,
        public array $command,
    ) {}
}
