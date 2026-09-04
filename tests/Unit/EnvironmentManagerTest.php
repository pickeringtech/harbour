<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Environment\EnvironmentManager;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;
use PickeringTech\Harbour\State\WorkspaceState;

final class EnvironmentManagerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/harbour-env-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function test_it_preserves_renders_and_exactly_restores_existing_environment(): void
    {
        file_put_contents($this->directory.'/.env', "VALUABLE=\"original value\"\n");
        $state = WorkspaceState::begin($this->identity(), $this->directory);
        $manager = new EnvironmentManager($this->directory);

        $state = $manager->prepare($state);
        $state = $manager->render($state, "APP_PORT=8123\n");
        self::assertSame("APP_PORT=8123\n", file_get_contents($this->directory.'/.env'));

        $manager->restore($state, false);
        self::assertSame("VALUABLE=\"original value\"\n", file_get_contents($this->directory.'/.env'));
    }

    public function test_it_refuses_to_overwrite_a_manually_modified_render(): void
    {
        $state = WorkspaceState::begin($this->identity(), $this->directory);
        $manager = new EnvironmentManager($this->directory);
        $state = $manager->render($manager->prepare($state), "APP_PORT=8123\n");
        file_put_contents($this->directory.'/.env', "MANUAL=true\n");

        $this->expectException(HarbourException::class);
        $manager->restore($state, false);
    }

    public function test_render_guard_allows_first_render_and_requires_force_after_manual_changes(): void
    {
        file_put_contents($this->directory.'/.env', "ORIGINAL=true\n");
        $manager = new EnvironmentManager($this->directory);
        $state = $manager->prepare(WorkspaceState::begin($this->identity(), $this->directory));

        $manager->assertRenderable($state, false);
        $state = $manager->render($state, "GENERATED=true\n");
        file_put_contents($this->directory.'/.env', "MANUAL=true\n");

        try {
            $manager->assertRenderable($state, false);
            self::fail('A modified rendered environment must be protected.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::EnvironmentModified, $exception->errorCode);
            self::assertStringContainsString('.env.harbour', $exception->getMessage());
            self::assertStringContainsString('--force', $exception->getMessage());
        }

        $manager->assertRenderable($state, true);
        $state = $manager->render($state, "REPLACED=true\n");
        $manager->restore($state, false);
        self::assertSame("ORIGINAL=true\n", file_get_contents($this->directory.'/.env'));
    }

    public function test_force_archives_manual_changes_without_weakening_restore(): void
    {
        file_put_contents($this->directory.'/.env', "ORIGINAL=true\n");
        $state = WorkspaceState::begin($this->identity(), $this->directory);
        $manager = new EnvironmentManager($this->directory);
        $state = $manager->render($manager->prepare($state), "GENERATED=true\n");
        file_put_contents($this->directory.'/.env', "MANUAL=true\n");

        $manager->restore($state, true);

        self::assertSame("ORIGINAL=true\n", file_get_contents($this->directory.'/.env'));
        self::assertSame("MANUAL=true\n", file_get_contents($this->directory.'/.harbour/backups/env.modified'));
    }

    public function test_it_refuses_an_internal_directory_symbolic_link(): void
    {
        $external = sys_get_temp_dir().'/harbour-env-external-'.bin2hex(random_bytes(6));
        mkdir($external, 0700, true);
        symlink($external, $this->directory.'/.harbour');
        file_put_contents($this->directory.'/.env', "ORIGINAL=true\n");

        try {
            $this->expectException(HarbourException::class);
            (new EnvironmentManager($this->directory))->prepare(WorkspaceState::begin($this->identity(), $this->directory));
        } finally {
            @unlink($this->directory.'/.harbour');
            @rmdir($external);
        }
    }

    private function identity(): WorkspaceIdentity
    {
        return new WorkspaceIdentity('ws_test', 'test-a1b2c3d4', str_repeat('a', 64), 'main');
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
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
