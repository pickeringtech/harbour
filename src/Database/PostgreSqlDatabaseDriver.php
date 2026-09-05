<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Database;

use PDO;
use PickeringTech\Harbour\Contracts\DatabaseLifecycleDriver;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\State\OwnedResource;
use Throwable;

final readonly class PostgreSqlDatabaseDriver implements DatabaseLifecycleDriver
{
    public function __construct(private OwnershipMarker $marker = new OwnershipMarker) {}

    public function supports(string $driver): bool
    {
        return $driver === 'pgsql';
    }

    public function create(OwnedResource $resource, string $workspacePath, DatabaseConfiguration $configuration): OwnedResource
    {
        $evidence = OwnedDatabaseEvidence::fromResource($resource);
        $database = $evidence->database;
        $this->assertIdentifier($evidence->database);
        if ($resource->driver !== 'pgsql' || $evidence->fingerprint !== $configuration->fingerprint()) {
            throw new HarbourException(ErrorCode::DatabaseNotOwned, 'PostgreSQL creation evidence does not match its connection.');
        }
        $admin = $this->connect($configuration, $configuration->adminDatabase ?: 'postgres');

        if ($this->databaseExists($admin, $database)) {
            $target = $this->connect($configuration, $database);
            if ($this->marker->matches($target, $evidence)) {
                return $resource;
            }
            if ($resource->creationPending() && $this->marker->reassignIfOwnedByWorkspace($target, $evidence)) {
                return $resource;
            }

            throw new HarbourException(ErrorCode::DatabaseCreationFailed, "Refusing to claim existing PostgreSQL database [{$database}].");
        }

        try {
            $admin->exec('CREATE DATABASE '.$this->quoteIdentifier($database));
            $target = $this->connect($configuration, $database);
            $this->marker->create($target, $evidence->workspaceId, $evidence->resourceId, $evidence->token);
        } catch (Throwable $exception) {
            if ($this->databaseExists($admin, $database)) {
                $admin->exec('DROP DATABASE '.$this->quoteIdentifier($database).' WITH (FORCE)');
            }

            throw new HarbourException(ErrorCode::DatabaseCreationFailed, "Unable to create PostgreSQL database [{$database}].", [], $exception);
        }

        return $resource;
    }

    public function exists(OwnedResource $resource, DatabaseConfiguration $configuration): bool
    {
        $evidence = OwnedDatabaseEvidence::fromResource($resource);
        $this->assertIdentifier($evidence->database);
        if ($evidence->fingerprint !== $configuration->fingerprint()) {
            return false;
        }
        $admin = $this->connect($configuration, $configuration->adminDatabase ?: 'postgres');

        if (! $this->databaseExists($admin, $evidence->database)) {
            return false;
        }

        return $this->marker->matches($this->connect($configuration, $evidence->database), $evidence);
    }

    public function destroy(OwnedResource $resource, DatabaseConfiguration $configuration, string $workspacePath): void
    {
        $evidence = OwnedDatabaseEvidence::fromResource($resource);
        $this->assertIdentifier($evidence->database);

        if ($evidence->fingerprint !== $configuration->fingerprint()) {
            throw new HarbourException(ErrorCode::DatabaseNotOwned, 'PostgreSQL connection fingerprint does not match the owned resource.');
        }

        $admin = $this->connect($configuration, $configuration->adminDatabase ?: 'postgres');

        if (! $this->databaseExists($admin, $evidence->database)) {
            return;
        }

        $target = $this->connect($configuration, $evidence->database);

        if (! $this->marker->matches($target, $evidence)) {
            throw new HarbourException(ErrorCode::DatabaseNotOwned, 'PostgreSQL ownership marker does not match Harbour state.');
        }

        unset($target);
        $admin->exec('DROP DATABASE '.$this->quoteIdentifier($evidence->database).' WITH (FORCE)');
    }

    private function connect(DatabaseConfiguration $configuration, string $database): PDO
    {
        $dsn = 'pgsql:dbname='.$database;
        $dsn .= $configuration->host !== null ? ';host='.$configuration->host : '';
        $dsn .= $configuration->port !== null ? ';port='.$configuration->port : '';

        try {
            return new PDO($dsn, $configuration->username, $configuration->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $exception) {
            throw new HarbourException(ErrorCode::DatabaseCreationFailed, 'Unable to connect to PostgreSQL.', [], $exception);
        }
    }

    /** @phpstan-impure */
    private function databaseExists(PDO $pdo, string $database): bool
    {
        $statement = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $statement->execute([$database]);

        return $statement->fetchColumn() !== false;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.$identifier.'"';
    }

    private function assertIdentifier(string $identifier): void
    {
        if (! preg_match('/^[a-z][a-z0-9_]{0,62}$/', $identifier)) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'Unsafe PostgreSQL database identifier.');
        }
    }
}
