<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Integration;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use PickeringTech\Harbour\Contracts\WorkspaceIdentityStrategy;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Identity\WorkspaceContext;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;
use PickeringTech\Harbour\Tests\TestCase;
use PickeringTech\Harbour\WorkspaceManager;

final class PackageIntegrationTest extends TestCase
{
    public function test_commands_are_registered(): void
    {
        $commands = $this->application()->make(Kernel::class)->all();

        foreach (['workspace:install', 'workspace:setup', 'workspace:teardown', 'workspace:status', 'workspace:env', 'workspace:render', 'workspace:debug'] as $name) {
            self::assertArrayHasKey($name, $commands);
        }
    }

    public function test_setup_generates_a_redacted_application_key_when_a_new_worktree_has_none(): void
    {
        unlink($this->workspaceDirectory.'/.env');
        file_put_contents($this->workspaceDirectory.'/.env.harbour', "APP_KEY=\${APP_KEY}\nAPP_PORT=\${APP_PORT}\n");
        $manager = $this->application()->make(WorkspaceManager::class);

        $workspace = $manager->setup();
        $contents = (string) file_get_contents($this->workspaceDirectory.'/.env');

        self::assertMatchesRegularExpression('/APP_KEY=base64:[A-Za-z0-9+\\/=]{44}/', $contents);
        self::assertSame('[REDACTED]', $workspace->variables()->debug()['APP_KEY']['value']);
        self::assertSame('generated_workspace_secret', $workspace->variables()->debug()['APP_KEY']['source']);
        self::assertArrayNotHasKey('APP_KEY', $workspace->state()->variables);

        $manager->teardown(true);
        self::assertFileDoesNotExist($this->workspaceDirectory.'/.env');
    }

    public function test_setup_and_teardown_are_idempotent_and_restore_environment(): void
    {
        $manager = $this->application()->make(WorkspaceManager::class);

        $first = $manager->setup();
        $second = $manager->setup();

        self::assertSame($first->identity()->id(), $second->identity()->id());
        self::assertSame($first->ports(), $second->ports());
        self::assertNull($second->variables()->get('ORIGINAL'));
        self::assertFileExists($this->workspaceDirectory.'/.harbour.json');
        self::assertStringContainsString('APP_PORT=', (string) file_get_contents($this->workspaceDirectory.'/.env'));

        $manager->teardown(true);
        $manager->teardown(true);

        self::assertFileDoesNotExist($this->workspaceDirectory.'/.harbour.json');
        self::assertSame("ORIGINAL=yes\n", file_get_contents($this->workspaceDirectory.'/.env'));
    }

    public function test_identity_strategy_is_replaceable_through_the_container(): void
    {
        $this->application()->make(ConfigRepository::class)->set('harbour.identity.strategy', FixedIdentityStrategy::class);
        $this->application()->forgetInstance(WorkspaceIdentityStrategy::class);
        $this->application()->forgetInstance(WorkspaceManager::class);

        $workspace = $this->application()->make(WorkspaceManager::class)->setup();

        self::assertSame('ws_custom', $workspace->identity()->id());
    }

    public function test_teardown_does_not_depend_on_the_current_template(): void
    {
        $manager = $this->application()->make(WorkspaceManager::class);
        $manager->setup();
        unlink($this->workspaceDirectory.'/.env.harbour');

        $manager->teardown(true);

        self::assertSame("ORIGINAL=yes\n", file_get_contents($this->workspaceDirectory.'/.env'));
        self::assertFileDoesNotExist($this->workspaceDirectory.'/.harbour.json');
    }

    public function test_modified_environment_is_rejected_before_teardown_changes_workspace_state(): void
    {
        $manager = $this->application()->make(WorkspaceManager::class);
        $workspace = $manager->setup();
        file_put_contents($this->workspaceDirectory.'/.env', "MANUAL=true\n");

        try {
            $manager->teardown(false);
            self::fail('Expected modified environment protection.');
        } catch (HarbourException) {
            $current = $manager->current();
            self::assertNotNull($current);
            self::assertSame('ready', $current->state()->status);
            self::assertSame($workspace->ports(), $current->ports());
            self::assertFileExists($this->workspaceDirectory.'/.harbour.json');
        } finally {
            $manager->teardown(true);
        }
    }

    public function test_render_reuses_a_required_secret_without_persisting_or_exposing_it(): void
    {
        file_put_contents($this->workspaceDirectory.'/.env', "APP_KEY=valuable-secret\n");
        file_put_contents($this->workspaceDirectory.'/.env.harbour', "APP_KEY=\${APP_KEY}\nAPP_PORT=\${APP_PORT}\n");
        $manager = $this->application()->make(WorkspaceManager::class);
        $manager->setup();

        self::assertArrayNotHasKey('APP_KEY', $manager->current()?->state()->variables ?? []);
        self::assertStringContainsString('APP_KEY=valuable-secret', (string) file_get_contents($this->workspaceDirectory.'/.env'));

        $rendered = $manager->render();
        self::assertSame('[REDACTED]', $rendered->variables()->debug()['APP_KEY']['value']);
        self::assertStringContainsString('APP_KEY=valuable-secret', (string) file_get_contents($this->workspaceDirectory.'/.env'));
        $manager->teardown(true);
    }

    public function test_status_has_a_stable_json_shape(): void
    {
        self::assertSame(0, Artisan::call('workspace:status', ['--json' => true]));
        $output = Artisan::output();
        self::assertStringContainsString('"version":1', $output);
        self::assertStringContainsString('"ok":true', $output);
    }
}

final class FixedIdentityStrategy implements WorkspaceIdentityStrategy
{
    public function resolve(WorkspaceContext $context): WorkspaceIdentity
    {
        return new WorkspaceIdentity('ws_custom', 'custom-a1b2c3d4', str_repeat('b', 64), 'custom');
    }
}
