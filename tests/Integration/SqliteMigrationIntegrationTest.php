<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Integration;

use Illuminate\Support\ServiceProvider;
use PDO;
use PickeringTech\Harbour\Tests\TestCase;
use PickeringTech\Harbour\WorkspaceManager;

final class SqliteMigrationIntegrationTest extends TestCase
{
    public function test_setup_runs_real_laravel_migrations_and_teardown_removes_the_owned_database(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The pdo_sqlite extension is required.');
        }

        $manager = $this->application()->make(WorkspaceManager::class);
        $manager->setup();
        $manager->setup();
        $database = $this->workspaceDirectory.'/database/harbour.sqlite';
        $pdo = new PDO('sqlite:'.$database);

        $statement = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'harbour_migration_probe'");
        self::assertNotFalse($statement);
        self::assertSame('harbour_migration_probe', $statement->fetchColumn());

        unset($pdo);
        $manager->teardown(true);
        self::assertFileDoesNotExist($database);
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), MigrationFixtureServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('harbour.database.enabled', true);
        $app['config']->set('harbour.database.connection', 'sqlite');
        $app['config']->set('harbour.database.sqlite_path', 'database/harbour.sqlite');
        $app['config']->set('harbour.database.migrate', true);
    }
}

final class MigrationFixtureServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Fixtures/migrations');
    }
}
