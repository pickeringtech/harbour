<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Database\DatabaseConfiguration;
use PickeringTech\Harbour\Database\DatabaseManager;
use PickeringTech\Harbour\Database\SqliteDatabaseDriver;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;

final class SqliteDatabaseDriverTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The pdo_sqlite extension is required.');
        }

        $this->directory = sys_get_temp_dir().'/harbour-sqlite-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        if ($this->directory === '') {
            return;
        }

        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function test_it_creates_marks_and_destroys_an_owned_database(): void
    {
        $driver = new SqliteDatabaseDriver;
        $path = $this->directory.'/harbour.sqlite';
        $configuration = new DatabaseConfiguration('sqlite', database: $path);
        $identity = new WorkspaceIdentity('ws_test', 'test-a1b2c3d4', str_repeat('a', 64), 'main');

        $resource = (new DatabaseManager([$driver]))->prepare($identity, $configuration, $path);
        $resource = $driver->create($resource, $this->directory, $configuration);

        self::assertFileExists($path);
        self::assertTrue($driver->exists($resource, $configuration));
        self::assertSame($resource, $driver->create($resource, $this->directory, $configuration));
        $driver->destroy($resource, $configuration, $this->directory);
        self::assertFileDoesNotExist($path);
        $driver->destroy($resource, $configuration, $this->directory);
    }

    public function test_it_never_claims_or_destroys_a_preexisting_file(): void
    {
        $path = $this->directory.'/existing.sqlite';
        new PDO('sqlite:'.$path);

        $this->expectException(HarbourException::class);
        $driver = new SqliteDatabaseDriver;
        $configuration = new DatabaseConfiguration('sqlite', database: $path);
        $resource = (new DatabaseManager([$driver]))->prepare($this->identity(), $configuration, $path);
        $driver->create($resource, $this->directory, $configuration);
    }

    public function test_it_rejects_paths_outside_the_workspace(): void
    {
        $this->expectException(HarbourException::class);
        $driver = new SqliteDatabaseDriver;
        $configuration = new DatabaseConfiguration('sqlite', database: '/tmp/escaped.sqlite');
        $resource = (new DatabaseManager([$driver]))->prepare($this->identity(), $configuration, '/tmp/escaped.sqlite');
        $driver->create($resource, $this->directory, $configuration);
    }

    private function identity(): WorkspaceIdentity
    {
        return new WorkspaceIdentity('ws_test', 'test-a1b2c3d4', str_repeat('a', 64), 'main');
    }
}
