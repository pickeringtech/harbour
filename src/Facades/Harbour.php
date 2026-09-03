<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Facades;

use Illuminate\Support\Facades\Facade;
use PickeringTech\Harbour\WorkspaceManager;

/** @method static \PickeringTech\Harbour\Workspace setup(bool $fresh = false) @method static void teardown(bool $force = false) @method static \PickeringTech\Harbour\Workspace|null current() @method static array status() */
final class Harbour extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WorkspaceManager::class;
    }
}
