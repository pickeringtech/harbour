<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests;

use Illuminate\Foundation\Application;
use LogicException;
use Orchestra\Testbench\TestCase as Orchestra;
use PickeringTech\Harbour\HarbourServiceProvider;

abstract class TestCase extends Orchestra
{
    protected string $workspaceDirectory;

    protected function setUp(): void
    {
        $this->workspaceDirectory = sys_get_temp_dir().'/harbour-laravel-'.bin2hex(random_bytes(6));
        mkdir($this->workspaceDirectory, 0700, true);
        file_put_contents($this->workspaceDirectory.'/.env.harbour', "APP_URL=\${APP_URL}\nAPP_PORT=\${APP_PORT}\nREDIS_PREFIX=\${REDIS_PREFIX}\nSESSION_COOKIE=\${SESSION_COOKIE}\nVITE_PORT=\${VITE_PORT}\nREVERB_PORT=\${REVERB_PORT}\n");
        file_put_contents($this->workspaceDirectory.'/.env', "ORIGINAL=yes\n");
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->workspaceDirectory);
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [HarbourServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('harbour.enabled', true);
        $app['config']->set('harbour.workspace_path', $this->workspaceDirectory);
        $app['config']->set('harbour.database.enabled', false);
        $app['config']->set('harbour.database.migrate', false);
        $app['config']->set('harbour.registry.path', $this->workspaceDirectory.'/.registry');
        $app['config']->set('harbour.ports.allocations', [
            'APP_PORT' => ['range' => [18200, 18299]],
            'VITE_PORT' => ['range' => [18300, 18399]],
            'REVERB_PORT' => ['range' => [18400, 18499]],
        ]);
    }

    protected function application(): Application
    {
        if (! $this->app instanceof Application) {
            throw new LogicException('The Laravel test application is unavailable.');
        }

        return $this->app;
    }

    private function removeDirectory(string $path): void
    {
        if (! isset($this->workspaceDirectory) || ! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.'/'.$entry;
            is_dir($child) ? $this->removeDirectory($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
