<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Database;

use PDO;
use PickeringTech\Harbour\Contracts\DatabaseLifecycleDriver;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\State\OwnedResource;
use Throwable;

final readonly class MySqlDatabaseDriver implements DatabaseLifecycleDriver
{
    public function __construct(private OwnershipMarker $marker = new OwnershipMarker) {}

    public function supports(string $driver): bool
    {
        return in_array($driver, ['mysql', 'mariadb'], true);
    }

    public function create(OwnedResource $resource, string $workspacePath, DatabaseConfiguration $configuration): OwnedResource
    {
        $evidence = OwnedDatabaseEvidence::fromResource($resource);
        $database = $evidence->database;
        $this->assertIdentifier($database);
        $this->assertCharset($configuration->charset);
        if (! in_array($resource->driver, ['mysql', 'mariadb'], true) || $evidence->fingerprint !== $configuration->fingerprint()) {
            throw new HarbourException(ErrorCode::DatabaseNotOwned, 'MySQL/MariaDB creation evidence does not match its connection.');
        }
        $admin = $this->connect($configuration);

        if ($this->databaseExists($admin, $database)) {
            $target = $this->connect($configuration, $database);
            if ($this->marker->matches($target, $evidence)) {
                return $resource;
            }
            if ($resource->creationPending() && $this->marker->reassignIfOwnedByWorkspace($target, $evidence)) {
                return $resource;
            }

            throw new HarbourException(ErrorCode::DatabaseCreationFailed, "Refusing to claim existing MySQL/MariaDB database [{$database}].");
        }

        try {
            $admin->exec('CREATE DATABASE '.$this->quoteIdentifier($database).' CHARACTER SET '.$configuration->charset);
            $target = $this->connect($configuration, $database);
            $this->marker->create($target, $evidence->workspaceId, $evidence->resourceId, $evidence->token);
            unset($target);
        } catch (Throwable $exception) {
            // MariaDB/MySQL may refuse or delay cleanup while the connection used
            // to write the marker is still alive.
            unset($target);
            if ($this->databaseExists($admin, $database)) {
                $admin->exec('DROP DATABASE '.$this->quoteIdentifier($database));
            }

            throw new HarbourException(ErrorCode::DatabaseCreationFailed, "Unable to create MySQL/MariaDB database [{$database}].", [], $exception);
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

        $admin = $this->connect($configuration);
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
            throw new HarbourException(ErrorCode::DatabaseNotOwned, 'MySQL/MariaDB connection fingerprint does not match the owned resource.');
        }

        $admin = $this->connect($configuration);

        if (! $this->databaseExists($admin, $evidence->database)) {
            return;
        }

        if (! $this->marker->matches($this->connect($configuration, $evidence->database), $evidence)) {
            throw new HarbourException(ErrorCode::DatabaseNotOwned, 'MySQL/MariaDB ownership marker does not match Harbour state.');
        }

        $admin->exec('DROP DATABASE '.$this->quoteIdentifier($evidence->database));
    }

    private function connect(DatabaseConfiguration $configuration, ?string $database = null): PDO
    {
        $dsn = 'mysql:';
        $dsn .= $configuration->unixSocket !== null && $configuration->unixSocket !== ''
            ? 'unix_socket='.$configuration->unixSocket
            : 'host='.($configuration->host ?? '127.0.0.1').';port='.($configuration->port ?? 3306);
        $dsn .= $database !== null ? ';dbname='.$database : '';
        $dsn .= ';charset='.$configuration->charset;

        try {
            return new PDO($dsn, $configuration->username, $configuration->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $exception) {
            throw new HarbourException(ErrorCode::DatabaseCreationFailed, 'Unable to connect to MySQL/MariaDB.', [], $exception);
        }
    }

    /** @phpstan-impure */
    private function databaseExists(PDO $pdo, string $database): bool
    {
        $statement = $pdo->prepare('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?');
        $statement->execute([$database]);

        return $statement->fetchColumn() !== false;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.$identifier.'`';
    }

    private function assertIdentifier(string $identifier): void
    {
        if (! preg_match('/^[a-z][a-z0-9_]{0,62}$/', $identifier)) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'Unsafe MySQL/MariaDB database identifier.');
        }
    }

    private function assertCharset(string $charset): void
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $charset)) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'Unsafe MySQL/MariaDB charset.');
        }
    }
}
