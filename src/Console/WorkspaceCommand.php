<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Console;

use Illuminate\Console\Command;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use Throwable;

abstract class WorkspaceCommand extends Command
{
    /** @param callable(): int $operation */
    protected function executeSafely(bool $json, callable $operation): int
    {
        try {
            return $operation();
        } catch (Throwable $exception) {
            $error = $exception instanceof HarbourException
                ? $exception
                : new HarbourException(ErrorCode::UnsafeOperation, $exception->getMessage(), [], $exception);

            if ($json) {
                $this->line((string) json_encode([
                    'version' => 1,
                    'ok' => false,
                    'error' => $error->toArray(),
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $this->components->error($error->getMessage());
                if ($this->output->isVerbose()) {
                    $this->line('Error code: '.$error->errorCode->value);
                }
            }

            return self::FAILURE;
        }
    }
}
