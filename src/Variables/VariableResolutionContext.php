<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Variables;

use PickeringTech\Harbour\Identity\WorkspaceIdentity;

final readonly class VariableResolutionContext
{
    /** @param array<string, int> $ports */
    public function __construct(
        public WorkspaceIdentity $identity,
        public string $workspacePath,
        public string $projectName,
        public array $ports,
        public ?string $database,
    ) {}
}
