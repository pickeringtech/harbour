<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Support;

use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;

final class WorkspacePath
{
    public static function assertSafe(string $workspacePath, string $path): void
    {
        $root = realpath($workspacePath);
        if ($root === false || str_contains($path, "\0")
            || ($path !== $root && ! str_starts_with($path, $root.DIRECTORY_SEPARATOR))) {
            throw new HarbourException(ErrorCode::UnsafeOperation, "Path [{$path}] is outside the workspace.");
        }

        $relative = ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);
        $segments = $relative === '' ? [] : explode(DIRECTORY_SEPARATOR, $relative);
        $current = $root;

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new HarbourException(ErrorCode::UnsafeOperation, "Path [{$path}] is unsafe.");
            }

            $current .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($current)) {
                throw new HarbourException(ErrorCode::UnsafeOperation, "Path [{$path}] traverses a symbolic link.");
            }
        }
    }
}
