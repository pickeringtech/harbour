<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Database\DatabaseConfiguration;
use PickeringTech\Harbour\Database\DatabaseManager;
use PickeringTech\Harbour\Database\MySqlDatabaseDriver;
use PickeringTech\Harbour\Database\PostgreSqlDatabaseDriver;
use PickeringTech\Harbour\Database\SqliteDatabaseDriver;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;
use PickeringTech\Harbour\State\OwnedResource;

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
