<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Contracts;

use PickeringTech\Harbour\Variables\ResolvedVariable;
use PickeringTech\Harbour\Variables\VariableResolutionContext;

interface WorkspaceVariableResolver
{
    /** @return iterable<ResolvedVariable> */
    public function resolve(VariableResolutionContext $context): iterable;
}
