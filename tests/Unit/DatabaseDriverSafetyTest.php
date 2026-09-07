<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Database\DatabaseConfiguration;
use PickeringTech\Harbour\Database\DatabaseManager;
use PickeringTech\Harbour\Database\MySqlDatabaseDriver;
use PickeringTech\Harbour\Database\OwnedDatabaseEvidence;
use PickeringTech\Harbour\Database\OwnershipMarker;
use PickeringTech\Harbour\Database\PostgreSqlDatabaseDriver;
use PickeringTech\Harbour\Database\SqliteDatabaseDriver;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;
use PickeringTech\Harbour\State\OwnedResource;
use RuntimeException;

final class DatabaseDriverSafetyTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/harbour-database-safety-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function test_database_manager_rejects_an_unsupported_driver(): void
    {
        $configuration = new DatabaseConfiguration('unsupported');
        $resource = (new DatabaseManager([]))->prepare($this->identity(), $configuration, 'harbour_test');

        $this->assertHarbourCode(
            ErrorCode::InvalidConfiguration,
            fn () => (new DatabaseManager([]))->exists($resource, $configuration),
        );
    }

    public function test_mysql_rejects_unsafe_evidence_configuration_and_connection_failures(): void
    {
        $driver = new MySqlDatabaseDriver;
        $configuration = new DatabaseConfiguration('mysql', '127.0.0.1', $this->closedPort(), username: 'root', password: 'none');
        $resource = $this->resource('mysql', 'harbour_test', $configuration->fingerprint());

        $this->assertHarbourCode(ErrorCode::DatabaseNotOwned, fn () => $driver->create($this->resource('pgsql', 'harbour_test', $configuration->fingerprint()), $this->directory, $configuration));
        $this->assertHarbourCode(ErrorCode::DatabaseNotOwned, fn () => $driver->destroy($resource, new DatabaseConfiguration('mysql'), $this->directory));
        $this->assertHarbourCode(ErrorCode::UnsafeOperation, fn () => $driver->create($this->resource('mysql', 'bad-name', $configuration->fingerprint()), $this->directory, $configuration));

        $unsafeCharset = new DatabaseConfiguration('mysql', '127.0.0.1', 3306, username: 'root', charset: 'utf8;drop');
        $this->assertHarbourCode(ErrorCode::UnsafeOperation, fn () => $driver->create($this->resource('mysql', 'harbour_test', $unsafeCharset->fingerprint()), $this->directory, $unsafeCharset));
        $this->assertHarbourCode(ErrorCode::DatabaseCreationFailed, fn () => $driver->create($resource, $this->directory, $configuration));
        self::assertFalse($driver->exists($resource, new DatabaseConfiguration('mysql')));

        $socketConfiguration = new DatabaseConfiguration('mysql', unixSocket: $this->directory.'/missing.sock', username: 'root');
        $this->assertHarbourCode(
            ErrorCode::DatabaseCreationFailed,
            fn () => $driver->create($this->resource('mysql', 'harbour_test', $socketConfiguration->fingerprint()), $this->directory, $socketConfiguration),
        );
    }

    public function test_postgresql_rejects_unsafe_evidence_and_connection_failures(): void
    {
        $driver = new PostgreSqlDatabaseDriver;
        $configuration = new DatabaseConfiguration('pgsql', '127.0.0.1', $this->closedPort(), username: 'postgres', password: 'none');
        $resource = $this->resource('pgsql', 'harbour_test', $configuration->fingerprint());

        $this->assertHarbourCode(ErrorCode::DatabaseNotOwned, fn () => $driver->create($this->resource('mysql', 'harbour_test', $configuration->fingerprint()), $this->directory, $configuration));
        $this->assertHarbourCode(ErrorCode::DatabaseNotOwned, fn () => $driver->destroy($resource, new DatabaseConfiguration('pgsql'), $this->directory));
        $this->assertHarbourCode(ErrorCode::UnsafeOperation, fn () => $driver->create($this->resource('pgsql', 'bad-name', $configuration->fingerprint()), $this->directory, $configuration));
        $this->assertHarbourCode(ErrorCode::DatabaseCreationFailed, fn () => $driver->create($resource, $this->directory, $configuration));
        self::assertFalse($driver->exists($resource, new DatabaseConfiguration('pgsql')));
    }

    public function test_sqlite_rejects_mismatched_evidence_corruption_and_traversal(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The pdo_sqlite extension is required.');
        }
        $driver = new SqliteDatabaseDriver;
        $path = $this->directory.'/database.sqlite';
        $configuration = new DatabaseConfiguration('sqlite', database: $path);

        $this->assertHarbourCode(
            ErrorCode::DatabaseNotOwned,
            fn () => $driver->create($this->resource('pgsql', $path, $configuration->fingerprint()), $this->directory, $configuration),
        );
        $this->assertHarbourCode(
            ErrorCode::UnsafeOperation,
            fn () => $driver->create($this->resource('sqlite', '../escape.sqlite', $configuration->fingerprint()), $this->directory, $configuration),
        );

        file_put_contents($path, 'not a SQLite database');
        $resource = $this->resource('sqlite', $path, $configuration->fingerprint());
        self::assertFalse($driver->exists($resource, $configuration));
        $this->assertHarbourCode(ErrorCode::DatabaseNotOwned, fn () => $driver->destroy($resource, $configuration, $this->directory));
    }

    public function test_sqlite_reassigns_a_valid_stale_marker_only_to_the_same_workspace(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The pdo_sqlite extension is required.');
        }
        $driver = new SqliteDatabaseDriver;
        $path = $this->directory.'/database.sqlite';
        $configuration = new DatabaseConfiguration('sqlite', database: $path);
        $workspace = 'ws_'.str_repeat('a', 64);
        $original = new OwnedResource('db_'.str_repeat('b', 32), $workspace, 'database', 'sqlite', [
            'database' => $path,
            'connection_fingerprint' => $configuration->fingerprint(),
            'ownership_token' => str_repeat('c', 64),
            'creation_pending' => true,
        ]);
        $replacement = new OwnedResource('db_'.str_repeat('d', 32), $workspace, 'database', 'sqlite', [
            'database' => $path,
            'connection_fingerprint' => $configuration->fingerprint(),
            'ownership_token' => str_repeat('e', 64),
            'creation_pending' => true,
        ]);
        $confirmedCollision = new OwnedResource('db_'.str_repeat('f', 32), $workspace, 'database', 'sqlite', [
            'database' => $path,
            'connection_fingerprint' => $configuration->fingerprint(),
            'ownership_token' => str_repeat('1', 64),
        ]);

        $driver->create($original, $this->directory, $configuration);
        $this->assertHarbourCode(
            ErrorCode::DatabaseCreationFailed,
            fn () => $driver->create($confirmedCollision, $this->directory, $configuration),
        );
        self::assertTrue($driver->exists($original, $configuration));
        $driver->create($replacement, $this->directory, $configuration);

        self::assertFalse($driver->exists($original, $configuration));
        self::assertTrue($driver->exists($replacement, $configuration));
        $driver->destroy($replacement, $configuration, $this->directory);
    }

    public function test_sqlite_refuses_to_reassign_a_stale_marker_from_another_workspace(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The pdo_sqlite extension is required.');
        }
        $driver = new SqliteDatabaseDriver;
        $path = $this->directory.'/database.sqlite';
        $configuration = new DatabaseConfiguration('sqlite', database: $path);
        $original = new OwnedResource('db_'.str_repeat('b', 32), 'ws_'.str_repeat('a', 64), 'database', 'sqlite', [
            'database' => $path,
            'connection_fingerprint' => $configuration->fingerprint(),
            'ownership_token' => str_repeat('c', 64),
            'creation_pending' => true,
        ]);
        $collision = new OwnedResource('db_'.str_repeat('d', 32), 'ws_'.str_repeat('f', 64), 'database', 'sqlite', [
            'database' => $path,
            'connection_fingerprint' => $configuration->fingerprint(),
            'ownership_token' => str_repeat('e', 64),
            'creation_pending' => true,
        ]);

        $driver->create($original, $this->directory, $configuration);
        $this->assertHarbourCode(
            ErrorCode::DatabaseCreationFailed,
            fn () => $driver->create($collision, $this->directory, $configuration),
        );
        self::assertTrue($driver->exists($original, $configuration));
        $driver->destroy($original, $configuration, $this->directory);
    }

    public function test_sqlite_reports_directory_marker_inspection_and_delete_failures(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The pdo_sqlite extension is required.');
        }

        $blockedPath = $this->directory.'/blocked/database.sqlite';
        file_put_contents($this->directory.'/blocked', 'not a directory');
        $blockedConfiguration = new DatabaseConfiguration('sqlite', database: $blockedPath);
        $this->assertHarbourCode(
            ErrorCode::DatabaseCreationFailed,
            fn () => (new SqliteDatabaseDriver)->create(
                $this->resource('sqlite', $blockedPath, $blockedConfiguration->fingerprint()),
                $this->directory,
                $blockedConfiguration,
            ),
        );
        unlink($this->directory.'/blocked');

        $path = $this->directory.'/database.sqlite';
        $configuration = new DatabaseConfiguration('sqlite', database: $path);
        $resource = $this->resource('sqlite', $path, $configuration->fingerprint());
        $driver = new SqliteDatabaseDriver;
        $driver->create($resource, $this->directory, $configuration);

        $throwing = new SqliteDatabaseDriver(new ThrowingOwnershipMarker);
        self::assertFalse($throwing->exists($resource, $configuration));
        $this->assertHarbourCode(
            ErrorCode::DatabaseCreationFailed,
            fn () => $throwing->create($resource, $this->directory, $configuration),
        );

        chmod($this->directory, 0500);
        try {
            $driver->destroy($resource, $configuration, $this->directory);
            self::fail('An undeletable SQLite database must be reported.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::UnsafeOperation, $exception->errorCode);
        } finally {
            chmod($this->directory, 0700);
        }
        $driver->destroy($resource, $configuration, $this->directory);
    }

    public function test_sqlite_removes_a_partial_database_after_marker_creation_fails(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The pdo_sqlite extension is required.');
        }
        $path = $this->directory.'/failed.sqlite';
        $configuration = new DatabaseConfiguration('sqlite', database: $path);
        $resource = $this->resource('sqlite', $path, $configuration->fingerprint());

        $this->assertHarbourCode(
            ErrorCode::DatabaseCreationFailed,
            fn () => (new SqliteDatabaseDriver(new FailingSqliteOwnershipMarker))->create($resource, $this->directory, $configuration),
        );
        self::assertFileDoesNotExist($path);
    }

    public function test_ownership_marker_fails_closed_when_transaction_recovery_itself_fails(): void
    {
        $pdo = new class extends PDO
        {
            public function __construct() {}

            public function beginTransaction(): bool
            {
                throw new RuntimeException('transaction failed');
            }

            public function inTransaction(): bool
            {
                throw new RuntimeException('recovery failed');
            }
        };
        $evidence = OwnedDatabaseEvidence::fromResource(
            $this->resource('sqlite', 'database.sqlite', (new DatabaseConfiguration('sqlite'))->fingerprint()),
        );

        self::assertFalse((new OwnershipMarker)->reassignIfOwnedByWorkspace($pdo, $evidence));
    }

    /** @param callable(): mixed $operation */
    private function assertHarbourCode(ErrorCode $code, callable $operation): void
    {
        try {
            $operation();
            self::fail("Expected {$code->value}.");
        } catch (HarbourException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }

    private function resource(string $driver, string $database, string $fingerprint): OwnedResource
    {
        return new OwnedResource('db_'.str_repeat('a', 32), 'ws_test', 'database', $driver, [
            'database' => $database,
            'connection_fingerprint' => $fingerprint,
            'ownership_token' => str_repeat('b', 64),
        ]);
    }

    private function identity(): WorkspaceIdentity
    {
        return new WorkspaceIdentity('ws_test', 'test-a1b2c3d4', str_repeat('a', 64), 'main');
    }

    private function closedPort(): int
    {
        $errorNumber = 0;
        $errorMessage = '';
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        self::assertIsResource($socket, $errorMessage ?? 'Unable to bind a temporary socket.');
        $address = stream_socket_get_name($socket, false);
        if (! is_string($address)) {
            self::fail('Unable to determine the temporary socket address.');
        }
        $separator = strrchr($address, ':');
        if ($separator === false) {
            self::fail('Temporary socket address does not contain a port.');
        }
        $port = (int) substr($separator, 1);
        fclose($socket);

        return $port;
    }
}

final class ThrowingOwnershipMarker extends OwnershipMarker
{
    public function matches(PDO $pdo, OwnedDatabaseEvidence $evidence): bool
    {
        throw new RuntimeException('marker inspection failed');
    }
}

final class FailingSqliteOwnershipMarker extends OwnershipMarker
{
    public function create(PDO $pdo, string $workspaceId, string $resourceId, string $token): void
    {
        throw new RuntimeException('marker creation failed');
    }
}
