<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Integration;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\PendingCommand;
use PickeringTech\Harbour\Contracts\WorkspaceIdentityStrategy;
use PickeringTech\Harbour\Identity\WorkspaceContext;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;
use PickeringTech\Harbour\Tests\TestCase;
use PickeringTech\Harbour\WorkspaceManager;
use RuntimeException;

final class CommandIntegrationTest extends TestCase
{
    public function test_install_command_reports_idempotent_project_changes_in_human_and_json_formats(): void
    {
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\n    \"name\": \"acme/app\"\n}\n");

        self::assertSame(0, Artisan::call('workspace:install'));
        $output = Artisan::output();
        self::assertStringContainsString('Harbour project files are ready.', $output);
        self::assertStringContainsString('config/harbour.php', $output);
        self::assertFileExists($this->workspaceDirectory.'/config/harbour.php');

        self::assertSame(0, Artisan::call('workspace:install', ['--json' => true]));
        $output = Artisan::output();
        self::assertStringContainsString('"ok":true', $output);
        self::assertStringContainsString('"updated":[]', $output);
    }

    public function test_commands_report_absent_workspace_and_structured_errors(): void
    {
        self::assertSame(0, Artisan::call('workspace:status'));
        self::assertStringContainsString('absent', Artisan::output());

        self::assertSame(0, Artisan::call('workspace:status', ['--json' => true]));
        self::assertStringContainsString('"status":"absent"', Artisan::output());

        foreach ([
            ['workspace:env', ['--format' => 'json']],
            ['workspace:debug', ['--json' => true]],
            ['workspace:render', ['--json' => true]],
        ] as [$command, $arguments]) {
            self::assertSame(1, Artisan::call($command, $arguments));
            $output = Artisan::output();
            self::assertStringContainsString('"ok":false', $output);
            self::assertStringContainsString('UNSAFE_OPERATION', $output);
        }
    }

    public function test_commands_cover_human_and_machine_readable_workspace_workflows(): void
    {
        file_put_contents($this->workspaceDirectory.'/.env.harbour', "APP_URL=\${APP_URL}\nAPP_PORT=\${APP_PORT}\nTEXT=\${TEXT}\nSECRET_VALUE=\${SECRET_VALUE}\nVITE_PORT=\${VITE_PORT}\nREVERB_PORT=\${REVERB_PORT}\n");
        $this->application()->make(Repository::class)->set('harbour.variables', [
            'TEXT' => "line one\n\"quoted\"\\tail",
            'SECRET_VALUE' => ['value' => 'do-not-print-by-default', 'secret' => true],
        ]);

        self::assertSame(0, Artisan::call('workspace:setup'));
        $output = Artisan::output();
        self::assertStringContainsString('Harbour is ready.', $output);
        self::assertStringContainsString('Workspace', $output);
        self::assertStringContainsString('VITE', $output);

        self::assertSame(0, Artisan::call('workspace:setup', ['--json' => true]));
        self::assertStringContainsString('"ok":true', Artisan::output());

        self::assertSame(0, Artisan::call('workspace:status'));
        $output = Artisan::output();
        self::assertStringContainsString('Harbour Workspace', $output);
        self::assertStringContainsString('Application', $output);

        self::assertSame(0, Artisan::call('workspace:env'));
        $output = Artisan::output();
        self::assertStringContainsString('project_configuration', $output);
        self::assertStringNotContainsString('do-not-print-by-default', $output);

        self::assertSame(0, Artisan::call('workspace:env', ['--format' => 'json']));
        $output = Artisan::output();
        self::assertStringContainsString('"variables"', $output);
        self::assertStringNotContainsString('SECRET_VALUE', $output);

        self::assertSame(0, Artisan::call('workspace:env', ['--format' => 'dotenv', '--show-secrets' => true]));
        $output = Artisan::output();
        self::assertStringContainsString('TEXT="line one\\n\\"quoted\\"\\\\tail"', $output);
        self::assertStringContainsString('SECRET_VALUE="do-not-print-by-default"', $output);

        self::assertSame(0, Artisan::call('workspace:env', ['--format' => 'shell', '--show-secrets' => true]));
        self::assertStringContainsString("export SECRET_VALUE='do-not-print-by-default'", Artisan::output());

        self::assertSame(0, Artisan::call('workspace:env', ['--format' => 'table', '--show-secrets' => true]));
        self::assertStringContainsString('[REDACTED]', Artisan::output());

        self::assertSame(0, Artisan::call('workspace:debug', ['variable' => 'SECRET_VALUE']));
        self::assertStringContainsString('[REDACTED]', Artisan::output());
        self::assertSame(0, Artisan::call('workspace:debug', ['variable' => 'MISSING', '--json' => true]));
        self::assertStringContainsString('"variables":[]', Artisan::output());

        self::assertSame(0, Artisan::call('workspace:render'));
        self::assertStringContainsString('Environment rendered.', Artisan::output());
        self::assertSame(0, Artisan::call('workspace:render', ['--json' => true]));
        self::assertStringContainsString('"workspace"', Artisan::output());

        self::assertSame(1, Artisan::call('workspace:env', ['--format' => 'unsupported']));
        self::assertStringContainsString('Unknown environment format', Artisan::output());

        self::assertSame(0, Artisan::call('workspace:teardown', ['--force' => true, '--json' => true]));
        self::assertStringContainsString('"status":"absent"', Artisan::output());

        self::assertSame(0, Artisan::call('workspace:setup', ['--force' => true]));
        self::assertSame(0, Artisan::call('workspace:teardown', ['--force' => true]));
        self::assertStringContainsString('Workspace cleaned.', Artisan::output());
    }

    public function test_fresh_setup_can_be_declined_interactively(): void
    {
        $manager = $this->application()->make(WorkspaceManager::class);
        $workspace = $manager->setup();

        $setup = $this->artisan('workspace:setup', ['--fresh' => true]);
        self::assertInstanceOf(PendingCommand::class, $setup);
        $setup->expectsConfirmation('Recreate Harbour-owned workspace resources?', 'no')->assertSuccessful();
        self::assertSame($workspace->identity()->id(), $manager->current()?->identity()->id());

        $manager->teardown(true);
    }

    public function test_teardown_can_be_declined_interactively(): void
    {
        $manager = $this->application()->make(WorkspaceManager::class);
        $manager->setup();

        $teardown = $this->artisan('workspace:teardown');
        self::assertInstanceOf(PendingCommand::class, $teardown);
        $teardown->expectsConfirmation('Tear down resources proven to be owned by this Harbour workspace?', 'no')->assertSuccessful();
        self::assertNotNull($manager->current());
        $manager->teardown(true);
    }

    public function test_unexpected_command_failures_use_the_stable_error_envelope(): void
    {
        $config = $this->application()->make(Repository::class);
        $config->set('harbour.identity.strategy', ExplodingIdentityStrategy::class);
        $this->application()->forgetInstance(WorkspaceIdentityStrategy::class);
        $this->application()->forgetInstance(WorkspaceManager::class);

        self::assertSame(1, Artisan::call('workspace:setup', ['--json' => true]));
        $output = Artisan::output();
        self::assertStringContainsString('UNSAFE_OPERATION', $output);
        self::assertStringContainsString('identity exploded', $output);
    }
}

final class ExplodingIdentityStrategy implements WorkspaceIdentityStrategy
{
    public function resolve(WorkspaceContext $context): WorkspaceIdentity
    {
        throw new RuntimeException('identity exploded');
    }
}
