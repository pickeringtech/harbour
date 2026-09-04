<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Ports;

use JsonException;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Support\AtomicFile;

final class FilePortRegistry
{
    private const VERSION = 1;

    private readonly string $registryPath;

    private readonly string $lockPath;

    public function __construct(
        string $directory,
        private readonly AtomicFile $files = new AtomicFile,
    ) {
        $this->registryPath = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'ports.json';
        $this->lockPath = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'ports.lock';
    }

    public function reserve(string $workspaceId, string $workspacePath, PortRequirement $requirement): PortAllocation
    {
        return $this->locked(function (array $registry) use ($workspaceId, $workspacePath, $requirement): array {
            $registry['reservations'] = array_values(array_filter(
                $registry['reservations'],
                static fn (array $reservation): bool => is_dir($reservation['workspace_path']) || $reservation['workspace_id'] === $workspaceId,
            ));
            foreach ($registry['reservations'] as $index => $reservation) {
                if ($reservation['workspace_id'] === $workspaceId && $reservation['name'] === $requirement->name) {
                    if ($this->isAvailable($reservation['host'], $reservation['port'])) {
                        $registry['reservations'][$index]['workspace_path'] = $workspacePath;

                        return [$registry, new PortAllocation($requirement->name, $reservation['port'], $workspaceId, $reservation['host'])];
                    }

                    // The reservation outlived its bind availability. Discard it
                    // under the same lock and select another port in range.
                    unset($registry['reservations'][$index]);
                    $registry['reservations'] = array_values($registry['reservations']);
                    break;
                }
            }

            $used = array_column($registry['reservations'], 'port');
            $size = $requirement->maximum - $requirement->minimum + 1;
            $offset = (int) (hexdec(substr(hash('sha256', $workspaceId."\0".$requirement->name), 0, 7)) % $size);

            for ($attempt = 0; $attempt < $size; $attempt++) {
                $port = $requirement->minimum + (($offset + $attempt) % $size);

                if (in_array($port, $used, true) || ! $this->isAvailable($requirement->host, $port)) {
                    continue;
                }

                $registry['reservations'][] = [
                    'workspace_id' => $workspaceId,
                    'workspace_path' => $workspacePath,
                    'name' => $requirement->name,
                    'host' => $requirement->host,
                    'port' => $port,
                    'reserved_at' => gmdate(DATE_ATOM),
                ];

                return [$registry, new PortAllocation($requirement->name, $port, $workspaceId, $requirement->host)];
            }

            throw new HarbourException(
                ErrorCode::PortAllocationFailed,
                "Unable to allocate [{$requirement->name}] in {$requirement->minimum}-{$requirement->maximum}.",
                ['allocation' => $requirement->name],
            );
        });
    }

    public function release(string $workspaceId, string $name, int $port): bool
    {
        return $this->locked(function (array $registry) use ($workspaceId, $name, $port): array {
            $released = false;
            $registry['reservations'] = array_values(array_filter(
                $registry['reservations'],
                static function (array $reservation) use ($workspaceId, $name, $port, &$released): bool {
                    $owned = $reservation['workspace_id'] === $workspaceId
                        && $reservation['name'] === $name
                        && $reservation['port'] === $port;
                    $released = $released || $owned;

                    return ! $owned;
                },
            ));

            return [$registry, $released];
        });
    }

    public function reconcileDeletedWorkspaces(): int
    {
        return $this->locked(function (array $registry): array {
            $before = count($registry['reservations']);
            $registry['reservations'] = array_values(array_filter(
                $registry['reservations'],
                static fn (array $reservation): bool => is_dir($reservation['workspace_path']),
            ));

            return [$registry, $before - count($registry['reservations'])];
        });
    }

    public function releaseWorkspace(string $workspaceId): int
    {
        return $this->locked(function (array $registry) use ($workspaceId): array {
            $before = count($registry['reservations']);
            $registry['reservations'] = array_values(array_filter(
                $registry['reservations'],
                static fn (array $reservation): bool => $reservation['workspace_id'] !== $workspaceId,
            ));

            return [$registry, $before - count($registry['reservations'])];
        });
    }

    private function isAvailable(string $host, int $port): bool
    {
        $address = str_contains($host, ':') ? "tcp://[{$host}]:{$port}" : "tcp://{$host}:{$port}";
        $errno = 0;
        $error = '';
        $socket = @stream_socket_server($address, $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * @template T
     *
     * @param  callable(array{version: int, reservations: list<array{workspace_id: string, workspace_path: string, name: string, host: string, port: int, reserved_at: string}>}): array{array{version: int, reservations: list<array{workspace_id: string, workspace_path: string, name: string, host: string, port: int, reserved_at: string}>}, T}  $operation
     * @return T
     */
    private function locked(callable $operation): mixed
    {
        $directory = dirname($this->lockPath);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new HarbourException(ErrorCode::StateWriteFailed, "Unable to create registry directory [{$directory}].");
        }

        $handle = @fopen($this->lockPath, 'c+');

        if ($handle === false || ! flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new HarbourException(ErrorCode::PortAllocationFailed, 'Unable to acquire the port registry lock.');
        }

        try {
            $registry = $this->read();
            [$registry, $result] = $operation($registry);
            $json = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            $this->files->write($this->registryPath, $json);

            return $result;
        } catch (JsonException $exception) {
            throw new HarbourException(ErrorCode::StateCorrupted, 'The Harbour port registry is corrupted.', [], $exception);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array{version: int, reservations: list<array{workspace_id: string, workspace_path: string, name: string, host: string, port: int, reserved_at: string}>} */
    private function read(): array
    {
        if (! is_file($this->registryPath)) {
            return ['version' => self::VERSION, 'reservations' => []];
        }

        $contents = file_get_contents($this->registryPath);
        $decoded = is_string($contents) ? json_decode($contents, true, flags: JSON_THROW_ON_ERROR) : null;

        if (! is_array($decoded) || ($decoded['version'] ?? null) !== self::VERSION || ! is_array($decoded['reservations'] ?? null)) {
            throw new HarbourException(ErrorCode::StateCorrupted, 'The Harbour port registry is corrupted.');
        }

        $reservations = [];

        foreach ($decoded['reservations'] as $reservation) {
            if (! is_array($reservation)
                || ! is_string($reservation['workspace_id'] ?? null)
                || $reservation['workspace_id'] === ''
                || ! is_string($reservation['workspace_path'] ?? null)
                || $reservation['workspace_path'] === ''
                || ! is_string($reservation['name'] ?? null)
                || preg_match('/\A[A-Z][A-Z0-9_]*\z/', $reservation['name']) !== 1
                || ! is_string($reservation['host'] ?? null)
                || $reservation['host'] === ''
                || ! is_int($reservation['port'] ?? null)
                || $reservation['port'] < 1
                || $reservation['port'] > 65535
                || ! is_string($reservation['reserved_at'] ?? null)
                || $reservation['reserved_at'] === '') {
                throw new HarbourException(ErrorCode::StateCorrupted, 'The Harbour port registry is corrupted.');
            }

            $reservations[] = [
                'workspace_id' => $reservation['workspace_id'],
                'workspace_path' => $reservation['workspace_path'],
                'name' => $reservation['name'],
                'host' => $reservation['host'],
                'port' => $reservation['port'],
                'reserved_at' => $reservation['reserved_at'],
            ];
        }

        return ['version' => self::VERSION, 'reservations' => $reservations];
    }
}
