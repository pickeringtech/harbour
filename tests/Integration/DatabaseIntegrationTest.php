<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Contracts\DatabaseLifecycleDriver;
use PickeringTech\Harbour\Database\DatabaseConfiguration;
use PickeringTech\Harbour\Database\DatabaseManager;
use PickeringTech\Harbour\Database\MySqlDatabaseDriver;
use PickeringTech\Harbour\Database\PostgreSqlDatabaseDriver;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;
use PickeringTech\Harbour\State\OwnedResource;

final class DatabaseIntegrationTest extends TestCase
{
    #[DataProvider('servers')]
    public function test_real_server_database_lifecycle(string $driverName): void
    {
        if (getenv('HARBOUR_DATABASE_INTEGRATION') !== '1') {
            self::markTestSkipped('Set HARBOUR_DATABASE_INTEGRATION=1 to mutate configured test database servers.');
        }

        [$driver, $configuration] = $this->server($driverName);
        $hash = hash('sha256', $driverName.bin2hex(random_bytes(8)));
        $identity = new WorkspaceIdentity('ws_'.$hash, 'integration-'.substr($hash, 0, 8), $hash, 'integration');
        $database = 'harbour_test_'.substr($hash, 0, 12);

        $resource = (new DatabaseManager([$driver]))->prepare($identity, $configuration, $database);
        $resource = $driver->create($resource, sys_get_temp_dir(), $configuration);
        self::assertSame($resource, $driver->create($resource, sys_get_temp_dir(), $configuration));

        self::assertTrue($driver->exists($resource, $configuration));
        $driver->destroy($resource, $configuration, sys_get_temp_dir());
        self::assertFalse($driver->exists($resource, $configuration));
        $driver->destroy($resource, $configuration, sys_get_temp_dir());
    }

    #[DataProvider('servers')]
    public function test_real_server_refuses_to_drop_an_unowned_database(string $driverName): void
    {
        if (getenv('HARBOUR_DATABASE_INTEGRATION') !== '1') {
            self::markTestSkipped('Set HARBOUR_DATABASE_INTEGRATION=1 to mutate configured test database servers.');
        }
        [$driver, $configuration] = $this->server($driverName);
        $database = 'harbour_external_'.substr(hash('sha256', bin2hex(random_bytes(8))), 0, 12);
        $admin = $this->admin($configuration);
        $this->createExternal($admin, $configuration, $database);
        $resource = new OwnedResource('db_'.bin2hex(random_bytes(16)), 'ws_external', 'database', $driverName, [
            'database' => $database,
            'connection_fingerprint' => $configuration->fingerprint(),
            'ownership_token' => bin2hex(random_bytes(32)),
        ]);

        try {
            try {
                $driver->destroy($resource, $configuration, sys_get_temp_dir());
                self::fail('An unowned database must not be dropped.');
            } catch (HarbourException) {
                self::assertTrue($this->externalExists($admin, $configuration, $database));
            }
        } finally {
            $this->dropExternal($admin, $configuration, $database);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function servers(): iterable
    {
        yield 'PostgreSQL' => ['pgsql'];
        yield 'MySQL/MariaDB' => ['mysql'];
    }

    /** @return array{DatabaseLifecycleDriver, DatabaseConfiguration} */
    private function server(string $name): array
    {
        if ($name === 'pgsql') {
            if (! extension_loaded('pdo_pgsql')) {
                self::markTestSkipped('pdo_pgsql is unavailable.');
            }

            return [new PostgreSqlDatabaseDriver, new DatabaseConfiguration(
                'pgsql',
                getenv('POSTGRES_HOST') ?: '127.0.0.1',
                (int) (getenv('POSTGRES_PORT') ?: 5432),
                username: getenv('POSTGRES_USER') ?: 'postgres',
                password: getenv('POSTGRES_PASSWORD') ?: 'harbour',
                adminDatabase: 'postgres',
            )];
        }

        if (! extension_loaded('pdo_mysql')) {
            self::markTestSkipped('pdo_mysql is unavailable.');
        }

        return [new MySqlDatabaseDriver, new DatabaseConfiguration(
            'mysql',
            getenv('MYSQL_HOST') ?: '127.0.0.1',
            (int) (getenv('MYSQL_PORT') ?: 3306),
            username: getenv('MYSQL_USER') ?: 'root',
            password: getenv('MYSQL_PASSWORD') ?: 'harbour',
        )];
    }

    private function admin(DatabaseConfiguration $configuration): PDO
    {
        $dsn = $configuration->driver === 'pgsql'
            ? 'pgsql:host='.$configuration->host.';port='.$configuration->port.';dbname=postgres'
            : 'mysql:host='.$configuration->host.';port='.$configuration->port.';charset=utf8mb4';

        return new PDO($dsn, $configuration->username, $configuration->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function createExternal(PDO $pdo, DatabaseConfiguration $configuration, string $database): void
    {
        $pdo->exec($configuration->driver === 'pgsql'
            ? 'CREATE DATABASE "'.$database.'"'
            : 'CREATE DATABASE `'.$database.'`');
    }

    private function dropExternal(PDO $pdo, DatabaseConfiguration $configuration, string $database): void
    {
        $pdo->exec($configuration->driver === 'pgsql'
            ? 'DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)'
            : 'DROP DATABASE IF EXISTS `'.$database.'`');
    }

    private function externalExists(PDO $pdo, DatabaseConfiguration $configuration, string $database): bool
    {
        $statement = $pdo->prepare($configuration->driver === 'pgsql'
            ? 'SELECT 1 FROM pg_database WHERE datname = ?'
            : 'SELECT 1 FROM information_schema.schemata WHERE schema_name = ?');
        $statement->execute([$database]);

        return $statement->fetchColumn() !== false;
    }
}
