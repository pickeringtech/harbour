<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Integration;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\PendingCommand;
use PickeringTech\Harbour\Console\InstallCommand;
use PickeringTech\Harbour\Contracts\WorkspaceIdentityStrategy;
use PickeringTech\Harbour\Identity\WorkspaceContext;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;
use PickeringTech\Harbour\Tests\TestCase;
use PickeringTech\Harbour\WorkspaceManager;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

final class CommandIntegrationTest extends TestCase
{
    public function test_install_command_reports_idempotent_project_changes_in_human_and_json_formats(): void
    {
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\n    \"name\": \"acme/app\"\n}\n");
        $options = [
            '--database' => 'postgresql',
            '--cache' => 'redis',
            '--mail' => 'mailpit',
            '--with' => 'meilisearch,minio',
        ];

        self::assertSame(0, Artisan::call('workspace:install', $options));
        $output = Artisan::output();
        self::assertStringContainsString('Harbour project files are ready.', $output);
        self::assertStringContainsString('pgsql', $output);
        self::assertStringContainsString('redis', $output);
        self::assertStringContainsString('mailpit', $output);
        self::assertStringContainsString('config/harbour.php', $output);
        self::assertFileExists($this->workspaceDirectory.'/config/harbour.php');

        self::assertSame(0, Artisan::call('workspace:install', [...$options, '--json' => true]));
        $output = Artisan::output();
        self::assertStringContainsString('"ok":true', $output);
        self::assertStringContainsString('"updated":[]', $output);
        self::assertStringContainsString('"database":"pgsql"', $output);
    }

    public function test_install_command_guides_interactive_service_selection(): void
    {
        unlink($this->workspaceDirectory.'/.env.harbour');
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\n    \"name\": \"acme/app\"\n}\n");

        $command = $this->application()->make(InstallCommand::class);
        $command->setLaravel($this->application());
        $tester = new CommandTester($command);
        $tester->setInputs(['no', 'PostgreSQL', 'Redis', 'Mailpit', 'Meilisearch,Selenium']);

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('No external infrastructure configuration was detected.', $tester->getDisplay());

        $environment = (string) file_get_contents($this->workspaceDirectory.'/.env.harbour');
        self::assertStringContainsString('DB_CONNECTION=pgsql', $environment);
        self::assertStringContainsString('CACHE_STORE=redis', $environment);
        self::assertStringContainsString('MAIL_MAILER=smtp', $environment);
        self::assertStringContainsString('MEILISEARCH_HOST=', $environment);
        self::assertStringContainsString('DUSK_DRIVER_URL=', $environment);
    }

    public function test_json_install_requires_explicit_non_interactive_choices(): void
    {
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\n    \"name\": \"acme/app\"\n}\n");

        self::assertSame(1, Artisan::call('workspace:install', ['--json' => true]));
        self::assertStringContainsString('INSTALL_SELECTION_REQUIRED', Artisan::output());
        self::assertFileDoesNotExist($this->workspaceDirectory.'/config/harbour.php');
    }

    public function test_detect_option_accepts_sail_configuration_without_prompts(): void
    {
        unlink($this->workspaceDirectory.'/.env.harbour');
        file_put_contents($this->workspaceDirectory.'/composer.json', <<<'JSON'
        {
            "name": "acme/app",
            "require-dev": {"laravel/sail": "^1.0"}
        }
        JSON);
        file_put_contents($this->workspaceDirectory.'/compose.yaml', <<<'YAML'
        services:
          laravel.test:
            image: sail-8.4/app
          pgsql:
            image: postgres:17
          redis:
            image: redis:alpine
          mailpit:
            image: axllent/mailpit
        YAML);
        file_put_contents($this->workspaceDirectory.'/.env', <<<'ENV'
        DB_CONNECTION=pgsql
        DB_HOST=pgsql
        DB_PORT=5432
        CACHE_STORE=redis
        REDIS_HOST=redis
        REDIS_PORT=6379
        MAIL_MAILER=smtp
        MAIL_HOST=mailpit
        MAIL_PORT=1025
        FORWARD_DB_PORT=15432
        ENV);

        self::assertSame(0, Artisan::call('workspace:install', ['--detect' => true, '--json' => true]));
        $output = Artisan::output();

        self::assertStringContainsString('"database":"pgsql"', $output);
        self::assertStringContainsString('"services":["pgsql","redis","mailpit"]', $output);
        self::assertStringContainsString('"detected":true', $output);
        self::assertStringContainsString('"sail:compose.yaml"', $output);
        self::assertStringContainsString('DB_PORT=15432', (string) file_get_contents($this->workspaceDirectory.'/.env.harbour'));
    }

    public function test_interactive_install_accepts_the_detected_proposal_with_one_answer(): void
    {
        unlink($this->workspaceDirectory.'/.env.harbour');
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\n    \"name\": \"acme/app\"\n}\n");
        file_put_contents($this->workspaceDirectory.'/herd.yml', "services:\n  redis:\n    version: 7.4\n    port: 16379\n");
        file_put_contents($this->workspaceDirectory.'/.env', "CACHE_STORE=redis\nREDIS_PORT=16379\nMAIL_MAILER=log\n");

        $command = $this->application()->make(InstallCommand::class);
        $command->setLaravel($this->application());
        $tester = new CommandTester($command);
        $tester->setInputs(['yes']);

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('Harbour detected this project configuration.', $tester->getDisplay());
        self::assertStringContainsString('Detected from', $tester->getDisplay());
        self::assertStringContainsString('REDIS_PORT=16379', (string) file_get_contents($this->workspaceDirectory.'/.env.harbour'));
    }

    public function test_explicit_options_override_only_the_requested_detected_categories(): void
    {
        unlink($this->workspaceDirectory.'/.env.harbour');
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\n    \"name\": \"acme/app\"\n}\n");
        file_put_contents($this->workspaceDirectory.'/.env', "DB_CONNECTION=pgsql\nCACHE_STORE=redis\nMAIL_MAILER=log\nMEILISEARCH_HOST=http://127.0.0.1:7700\n");

        self::assertSame(0, Artisan::call('workspace:install', [
            '--detect' => true,
            '--database' => 'sqlite',
            '--json' => true,
        ]));
        $output = Artisan::output();

        self::assertStringContainsString('"database":"sqlite"', $output);
        self::assertStringContainsString('"services":["redis","meilisearch"]', $output);
    }

    public function test_sail_compatible_with_option_and_short_category_flags_are_supported(): void
    {
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\n    \"name\": \"acme/app\"\n}\n");

        self::assertSame(0, Artisan::call('workspace:install', [
            '--database' => 'mysql',
            '--cache' => 'memcache',
            '--mail' => 'log',
            '--with' => 'mysql,memcached,typesense,rustfs,rabbitmq,soketi',
            '--json' => true,
        ]));
        $output = Artisan::output();

        self::assertStringContainsString('"database":"mysql"', $output);
        self::assertStringContainsString('"cache":"memcached"', $output);
        self::assertStringContainsString('"services":["mysql","memcached","typesense","rustfs","rabbitmq","soketi"]', $output);
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
