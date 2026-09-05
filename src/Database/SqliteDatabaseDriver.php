<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Database;

use PDO;
use PickeringTech\Harbour\Contracts\DatabaseLifecycleDriver;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\State\OwnedResource;
use Throwable;

final readonly class SqliteDatabaseDriver implements DatabaseLifecycleDriver
{
    public function __construct(private OwnershipMarker $marker = new OwnershipMarker) {}

    public function supports(string $driver): bool
    {
        return $driver === 'sqlite';
    }

    public function create(OwnedResource $resource, string $workspacePath, DatabaseConfiguration $configuration): OwnedResource
    {
        $evidence = OwnedDatabaseEvidence::fromResource($resource);
        $database = $evidence->database;
        $path = $this->safePath($workspacePath, $database);

        if (file_exists($path) || is_link($path)) {
            if (is_file($path) && $resource->driver === 'sqlite' && $evidence->fingerprint === $configuration->fingerprint()) {
                try {
                    $pdo = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                    if ($this->marker->matches($pdo, $evidence)
                        || ($resource->creationPending() && $this->marker->reassignIfOwnedByWorkspace($pdo, $evidence))) {
                        return $resource;
                    }
                } catch (Throwable) {
                    // The existing file is not a database Harbour can prove it owns.
                }
            }

            throw new HarbourException(ErrorCode::DatabaseCreationFailed, "Refusing to claim existing SQLite database [{$path}].");
        }

        if ($resource->driver !== 'sqlite' || $evidence->fingerprint !== $configuration->fingerprint()) {
            throw new HarbourException(ErrorCode::DatabaseNotOwned, 'SQLite creation evidence does not match its connection.');
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new HarbourException(ErrorCode::DatabaseCreationFailed, "Unable to create SQLite directory [{$directory}].");
        }

        try {
            $pdo = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->marker->create($pdo, $evidence->workspaceId, $evidence->resourceId, $evidence->token);
            @chmod($path, 0600);
        } catch (Throwable $exception) {
            @unlink($path);

            throw new HarbourException(ErrorCode::DatabaseCreationFailed, 'Unable to create the SQLite workspace database.', [], $exception);
        }

        return $resource;
    }

    public function exists(OwnedResource $resource, DatabaseConfiguration $configuration): bool
    {
        $evidence = OwnedDatabaseEvidence::fromResource($resource);

        if (! is_file($evidence->database)) {
            return false;
        }

        try {
            $pdo = new PDO('sqlite:'.$evidence->database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            return $this->marker->matches($pdo, $evidence);
        } catch (Throwable) {
            return false;
        }
    }

    public function destroy(OwnedResource $resource, DatabaseConfiguration $configuration, string $workspacePath): void
    {
        $evidence = OwnedDatabaseEvidence::fromResource($resource);
        $path = $this->safePath($workspacePath, $evidence->database);

        if (! file_exists($path)) {
            return;
        }

        if ($evidence->fingerprint !== $configuration->fingerprint() || ! $this->exists($resource, $configuration)) {
            throw new HarbourException(ErrorCode::DatabaseNotOwned, 'Refusing to remove an SQLite database without matching ownership evidence.');
        }

        if (! @unlink($path)) {
            throw new HarbourException(ErrorCode::UnsafeOperation, "Unable to remove SQLite database [{$path}].");
        }
    }

    private function safePath(string $workspacePath, string $database): string
    {
        $root = realpath($workspacePath);

        if ($root === false || str_contains($database, "\0") || in_array('..', preg_split('~[\\\\/]~', $database) ?: [], true)) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'Invalid SQLite workspace path.');
        }

        $candidate = str_starts_with($database, DIRECTORY_SEPARATOR) ? $database : $root.DIRECTORY_SEPARATOR.$database;
        $parent = realpath(dirname($candidate));

        if ($parent === false) {
            $ancestor = dirname($candidate);
            while (! is_dir($ancestor) && dirname($ancestor) !== $ancestor) {
                $ancestor = dirname($ancestor);
            }
            $realAncestor = realpath($ancestor);
            $suffix = ltrim(substr(dirname($candidate), strlen($ancestor)), DIRECTORY_SEPARATOR);
            $parent = $realAncestor === false ? false : $realAncestor.($suffix === '' ? '' : DIRECTORY_SEPARATOR.$suffix);
        }

        $normalized = $parent === false ? false : rtrim($parent, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.basename($candidate);

        if ($normalized === false || ($normalized !== $root && ! str_starts_with($normalized, $root.DIRECTORY_SEPARATOR))) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'SQLite database path escapes the workspace.');
        }

        return $normalized;
    }
}
