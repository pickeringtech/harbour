<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Identity;

final readonly class WorkspaceContext
{
    public function __construct(
        public string $path,
        public string $projectName,
    ) {}
}
