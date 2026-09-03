<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Support;

use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;

final class AtomicFile
{
    public function write(string $path, string $contents, int $mode = 0600): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new HarbourException(ErrorCode::StateWriteFailed, "Unable to create directory [{$directory}].");
        }

        $temporary = $directory.'/.'.basename($path).'.'.bin2hex(random_bytes(8)).'.tmp';
        $handle = @fopen($temporary, 'xb');

        if ($handle === false) {
            throw new HarbourException(ErrorCode::StateWriteFailed, "Unable to create temporary file for [{$path}].");
        }

        try {
            if (fwrite($handle, $contents) !== strlen($contents)) {
                throw new HarbourException(ErrorCode::StateWriteFailed, "Unable to write [{$path}].");
            }

            fflush($handle);

            if (function_exists('fsync')) {
                fsync($handle);
            }
        } finally {
            fclose($handle);
        }

        @chmod($temporary, $mode);

        if (! @rename($temporary, $path)) {
            @unlink($temporary);

            throw new HarbourException(ErrorCode::StateWriteFailed, "Unable to atomically replace [{$path}].");
        }
    }
}
