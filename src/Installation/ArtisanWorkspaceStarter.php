<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Installation;

use JsonException;
use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Contracts\InstalledWorkspaceStarter;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Process\ProcessFailure;
use PickeringTech\Harbour\Support\WorkspacePath;
use Symfony\Component\Process\Process;

final readonly class ArtisanWorkspaceStarter implements InstalledWorkspaceStarter
{
    public function __construct(
        private string $workspacePath,
        private CommandRunner $processes,
    ) {}

    public function start(?callable $output = null): string
    {
        $artisan = $this->workspacePath.'/artisan';
        WorkspacePath::assertSafe($this->workspacePath, $artisan);

        if (! is_file($artisan) || is_link($artisan)) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'Unable to start the workspace because its Artisan entry point is missing or unsafe.');
        }

        $cachedConfiguration = $this->workspacePath.'/bootstrap/cache/config.php';
        WorkspacePath::assertSafe($this->workspacePath, $cachedConfiguration);
        if (is_link($cachedConfiguration)) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'Refusing to clear a symlinked Laravel configuration cache.');
        }
        if (is_file($cachedConfiguration)) {
            $clear = $this->processes->run([PHP_BINARY, $artisan, 'config:clear'], $this->workspacePath);
            if (! $clear->successful()) {
                throw new HarbourException(
                    ErrorCode::ProcessFailed,
                    'Harbour project files were created, but Laravel configuration cache could not be cleared.',
                    ProcessFailure::context($clear),
                );
            }
        }

        $command = [PHP_BINARY, $artisan, 'workspace:setup', '--json'];
        if ($output !== null) {
            $command[] = '--stream';
        }
        $stream = $output === null
            ? null
            : static function (string $type, string $buffer) use ($output): void {
                if ($type === Process::ERR) {
                    $output($type, $buffer);
                }
            };
        $result = $this->processes->run($command, $this->workspacePath, [], $stream);
        if (! $result->successful()) {
            $reported = self::errorFromOutput($result->output);
            if ($reported !== null) {
                throw new HarbourException(
                    $reported->errorCode,
                    $reported->getMessage(),
                    [...$reported->context, ...ProcessFailure::context($result)],
                    $reported,
                );
            }

            throw new HarbourException(
                ErrorCode::ProcessFailed,
                'Harbour project files were created, but the workspace could not be started. Run composer workspace:setup to retry.',
                ProcessFailure::context($result),
            );
        }

        self::workspaceFromOutput($result->output, ['exit_code' => $result->exitCode]);

        return $result->output;
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $context
     * @return array<string, mixed>
     */
    public static function workspaceFromOutput(string $output, array $context = []): array
    {
        $workspace = null;

        foreach (self::payloads($output) as $payload) {
            $candidate = $payload['workspace'] ?? null;
            if (is_array($candidate) && ! array_is_list($candidate) && ($payload['ok'] ?? null) === true) {
                $workspace = [];
                foreach ($candidate as $key => $value) {
                    if (is_string($key)) {
                        $workspace[$key] = $value;
                    }
                }
            }
        }

        if ($workspace === null) {
            throw new HarbourException(
                ErrorCode::ProcessFailed,
                'Harbour setup completed without a valid workspace status payload. Run composer workspace:setup to retry or composer workspace:status to inspect it.',
                $context,
            );
        }

        return $workspace;
    }

    private static function errorFromOutput(string $output): ?HarbourException
    {
        $reported = null;

        foreach (self::payloads($output) as $payload) {
            $error = $payload['error'] ?? null;
            if (($payload['ok'] ?? null) !== false || ! is_array($error)) {
                continue;
            }
            $code = is_string($error['code'] ?? null) ? ErrorCode::tryFrom($error['code']) : null;
            $message = $error['message'] ?? null;
            $context = $error['context'] ?? [];
            if ($code === null || ! is_string($message) || $message === '' || ! is_array($context)) {
                continue;
            }

            $safeContext = [];
            foreach ($context as $key => $value) {
                if (is_string($key)) {
                    $safeContext[$key] = $value;
                }
            }
            $reported = new HarbourException($code, $message, $safeContext);
        }

        return $reported;
    }

    /** @return list<array<string, mixed>> */
    private static function payloads(string $output): array
    {
        $payloads = [];
        $length = strlen($output);

        for ($start = 0; $start < $length; $start++) {
            if ($output[$start] !== '{') {
                continue;
            }

            $depth = 0;
            $quoted = false;
            $escaped = false;
            for ($end = $start; $end < $length; $end++) {
                $character = $output[$end];
                if ($quoted) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($character === '\\') {
                        $escaped = true;
                    } elseif ($character === '"') {
                        $quoted = false;
                    }

                    continue;
                }

                if ($character === '"') {
                    $quoted = true;
                } elseif ($character === '{') {
                    $depth++;
                } elseif ($character === '}' && --$depth === 0) {
                    try {
                        $payload = json_decode(substr($output, $start, $end - $start + 1), true, flags: JSON_THROW_ON_ERROR);
                    } catch (JsonException) {
                        break;
                    }
                    if (is_array($payload) && ! array_is_list($payload)) {
                        $safePayload = [];
                        foreach ($payload as $key => $value) {
                            if (is_string($key)) {
                                $safePayload[$key] = $value;
                            }
                        }
                        $payloads[] = $safePayload;
                    }

                    break;
                }
            }
        }

        return $payloads;
    }
}
