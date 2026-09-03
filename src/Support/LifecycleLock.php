<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Support;

use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;

final readonly class LifecycleLock
{
    public function __construct(private string $path) {}

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public function synchronized(callable $operation): mixed
    {
        $directory = dirname($this->path);
        $workspacePath = dirname(dirname($directory));
        WorkspacePath::assertSafe($workspacePath, $this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new HarbourException(ErrorCode::StateWriteFailed, 'Unable to create the Harbour lock directory.');
        }

        $handle = @fopen($this->path, 'c+');

        if ($handle === false || ! flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new HarbourException(ErrorCode::UnsafeOperation, 'Unable to acquire the workspace lifecycle lock.');
        }

        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
