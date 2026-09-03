<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Contracts;

use PickeringTech\Harbour\State\WorkspaceState;

interface WorkspaceStateRepository
{
    public function load(): ?WorkspaceState;

    public function save(WorkspaceState $state): void;

    public function delete(): void;
}
