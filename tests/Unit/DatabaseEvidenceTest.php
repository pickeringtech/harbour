<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Database\DatabaseConfiguration;
use PickeringTech\Harbour\Database\DatabaseManager;
use PickeringTech\Harbour\Database\OwnedDatabaseEvidence;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;
use PickeringTech\Harbour\State\OwnedResource;

final class DatabaseEvidenceTest extends TestCase
{
    public function test_laravel_configuration_is_normalized_and_every_connection_component_affects_the_fingerprint(): void
    {
        $configuration = DatabaseConfiguration::fromLaravel([
            'driver' => 'pgsql',
            'host' => 'db.test',
            'port' => '5432',
            'database' => 'app',
            'username' => 'developer',
            'password' => 'not-fingerprinted',
            'unix_socket' => '/tmp/postgres.sock',
            'charset' => 'utf8',
            'harbour_admin_database' => 'postgres',
        ]);

        self::assertSame('pgsql', $configuration->driver);
        self::assertSame('db.test', $configuration->host);
        self::assertSame(5432, $configuration->port);
        self::assertSame('app', $configuration->database);
        self::assertSame('developer', $configuration->username);
        self::assertSame('not-fingerprinted', $configuration->password);
        self::assertSame('/tmp/postgres.sock', $configuration->unixSocket);
        self::assertSame('utf8', $configuration->charset);
        self::assertSame('postgres', $configuration->adminDatabase);

        foreach ([
            new DatabaseConfiguration('mysql', 'db.test', 5432, unixSocket: '/tmp/postgres.sock', username: 'developer'),
            new DatabaseConfiguration('pgsql', 'other.test', 5432, unixSocket: '/tmp/postgres.sock', username: 'developer'),
            new DatabaseConfiguration('pgsql', 'db.test', 5433, unixSocket: '/tmp/postgres.sock', username: 'developer'),
            new DatabaseConfiguration('pgsql', 'db.test', 5432, unixSocket: '/tmp/other.sock', username: 'developer'),
            new DatabaseConfiguration('pgsql', 'db.test', 5432, unixSocket: '/tmp/postgres.sock', username: 'other'),
        ] as $different) {
            self::assertNotSame($configuration->fingerprint(), $different->fingerprint());
        }
    }

    public function test_prepared_database_evidence_has_stable_shape_and_entropy(): void
    {
        $configuration = new DatabaseConfiguration('pgsql', '127.0.0.1', 5432, username: 'postgres');
        $resource = (new DatabaseManager([]))->prepare($this->identity(), $configuration, 'harbour_test');

        self::assertMatchesRegularExpression('/\Adb_[a-f0-9]{32}\z/', $resource->id);
        self::assertSame('ws_test', $resource->workspaceId);
        self::assertSame('database', $resource->type);
        self::assertSame('pgsql', $resource->driver);
        self::assertSame('harbour_test', $resource->metadata['database']);
        self::assertSame($configuration->fingerprint(), $resource->metadata['connection_fingerprint']);
        $token = $resource->metadata['ownership_token'];
        self::assertIsString($token);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $token);
    }

    /** @param array<string, mixed> $metadata */
    #[DataProvider('invalidEvidence')]
    public function test_each_database_ownership_field_is_required(bool $created, string $type, array $metadata): void
    {
        $this->expectException(HarbourException::class);

        OwnedDatabaseEvidence::fromResource(new OwnedResource('db_123', 'ws_test', $type, 'sqlite', $metadata, $created));
    }

    /** @return iterable<string, array{bool, string, array<string, mixed>}> */
    public static function invalidEvidence(): iterable
    {
        $valid = ['ownership_token' => 'token', 'database' => 'database', 'connection_fingerprint' => 'fingerprint'];

        yield 'creation marker' => [false, 'database', $valid];
        yield 'resource type' => [true, 'docker_container', $valid];
        yield 'token type' => [true, 'database', [...$valid, 'ownership_token' => 123]];
        yield 'empty token' => [true, 'database', [...$valid, 'ownership_token' => '']];
        yield 'database type' => [true, 'database', [...$valid, 'database' => 123]];
        yield 'empty database' => [true, 'database', [...$valid, 'database' => '']];
        yield 'fingerprint type' => [true, 'database', [...$valid, 'connection_fingerprint' => 123]];
    }

    private function identity(): WorkspaceIdentity
    {
        return new WorkspaceIdentity('ws_test', 'test-a1b2c3d4', str_repeat('a', 64), 'main');
    }
}
