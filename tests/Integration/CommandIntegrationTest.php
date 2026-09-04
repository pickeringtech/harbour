<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Integration;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\PendingCommand;
use PickeringTech\Harbour\Console\InstallCommand;
use PickeringTech\Harbour\Contracts\ApplicationLauncher;
use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Contracts\InstallationDependencyInstaller;
use PickeringTech\Harbour\Contracts\InstallationPreflight;
use PickeringTech\Harbour\Contracts\InstalledApplicationLauncher;
use PickeringTech\Harbour\Contracts\InstalledWorkspaceStarter;
use PickeringTech\Harbour\Contracts\WorkspaceIdentityStrategy;
use PickeringTech\Harbour\Contracts\WorkspaceStateRepository;
use PickeringTech\Harbour\Docker\ComposeManager;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Identity\WorkspaceContext;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;
use PickeringTech\Harbour\Installation\InstallationRequirement;
use PickeringTech\Harbour\Installation\InstallationRuntimeResolver;
use PickeringTech\Harbour\Installation\InstallationSelection;
use PickeringTech\Harbour\Installation\SystemInstallationPreflight;
use PickeringTech\Harbour\Process\ProcessResult;
use PickeringTech\Harbour\Tests\TestCase;
use PickeringTech\Harbour\Workspace;
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
        $tester->setInputs([
            'Choose components manually',
            'PostgreSQL',
            'Redis',
            'Mailpit',
            'Meilisearch,Selenium',
            'yes',
            'no',
        ]);

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringNotContainsString('No external infrastructure configuration was detected.', $tester->getDisplay());

        $environment = (string) file_get_contents($this->workspaceDirectory.'/.env.harbour');
        self::assertStringContainsString('DB_CONNECTION=pgsql', $environment);
        self::assertStringContainsString('CACHE_STORE=redis', $environment);
        self::assertStringContainsString('MAIL_MAILER=smtp', $environment);
        self::assertStringContainsString('MEILISEARCH_HOST=', $environment);
        self::assertStringContainsString('DUSK_DRIVER_URL=', $environment);
        self::assertFileExists($this->workspaceDirectory.'/docker-compose.harbour.yml');
        self::assertStringContainsString('127.0.0.1:${DB_PORT}:5432', (string) file_get_contents($this->workspaceDirectory.'/docker-compose.harbour.yml'));
    }

    public function test_json_install_requires_explicit_non_interactive_choices(): void
    {
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\n    \"name\": \"acme/app\"\n}\n");

        self::assertSame(1, Artisan::call('workspace:install', ['--json' => true]));
        self::assertStringContainsString('INSTALL_SELECTION_REQUIRED', Artisan::output());
        self::assertFileDoesNotExist($this->workspaceDirectory.'/config/harbour.php');
    }

    public function test_install_preflight_fails_after_selection_and_before_writing_project_files(): void
    {
        unlink($this->workspaceDirectory.'/.env.harbour');
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\n    \"name\": \"acme/app\"\n}\n");
        $originalComposer = (string) file_get_contents($this->workspaceDirectory.'/composer.json');
        $this->application()->instance(InstallationPreflight::class, new SystemInstallationPreflight(
            $this->application()->make(Repository::class),
            new SuccessfulCommandRunner,
            $this->workspaceDirectory,
            [],
            [],
        ));

        $options = [
            '--database' => 'pgsql',
            '--cache' => 'file',
            '--mail' => 'log',
            '--with' => 'none',
        ];

        self::assertSame(1, Artisan::call('workspace:install', $options));
        $humanOutput = Artisan::output();
        self::assertStringContainsString('Checking selected stack requirements', $humanOutput);
        self::assertStringContainsString('PHP extension pdo_pgsql', $humanOutput);
        self::assertStringContainsString('retry without repeating your choices', $humanOutput);
        self::assertStringContainsString('--database=pgsql --cache=file --mail=log --with=none', $humanOutput);

        self::assertSame(1, Artisan::call('workspace:install', [...$options, '--json' => true]));

        $output = Artisan::output();
        self::assertStringContainsString('HARBOUR_INSTALL_REQUIREMENTS_MISSING', $output);
        self::assertStringContainsString('extension:pdo_pgsql', $output);
        self::assertStringNotContainsString('--start', $output);

        self::assertSame(1, Artisan::call('workspace:install', [...$options, '--start' => true, '--json' => true]));
        self::assertStringContainsString('--redis-client=auto --start', Artisan::output());
        self::assertStringNotContainsString('--install-dependencies', Artisan::output());
        self::assertFileDoesNotExist($this->workspaceDirectory.'/.env.harbour');
        self::assertFileDoesNotExist($this->workspaceDirectory.'/config/harbour.php');
        self::assertFileDoesNotExist($this->workspaceDirectory.'/docker-compose.harbour.yml');
        self::assertFileDoesNotExist($this->workspaceDirectory.'/.gitignore');
        self::assertSame($originalComposer, file_get_contents($this->workspaceDirectory.'/composer.json'));
    }

    public function test_install_can_remediate_selected_composer_dependencies_without_repeating_selection(): void
    {
        unlink($this->workspaceDirectory.'/.env.harbour');
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\n    \"name\": \"acme/app\"\n}\n");
        $preflight = new RemediableInstallationPreflight;
        $dependencies = new FakeInstallationDependencyInstaller($preflight);
        $this->application()->instance(InstallationPreflight::class, $preflight);
        $this->application()->instance(InstallationDependencyInstaller::class, $dependencies);
        $this->application()->instance(InstallationRuntimeResolver::class, new InstallationRuntimeResolver);

        self::assertSame(0, Artisan::call('workspace:install', [
            '--database' => 'sqlite',
            '--cache' => 'redis',
            '--mail' => 'log',
            '--with' => 'meilisearch',
            '--install-dependencies' => true,
            '--json' => true,
        ]));

        self::assertTrue($dependencies->installed);
        self::assertSame(['package:predis/predis', 'package:laravel/scout'], array_map(
            static fn (InstallationRequirement $requirement): string => $requirement->id,
            $dependencies->requirements,
        ));
        self::assertStringContainsString('REDIS_CLIENT=predis', (string) file_get_contents($this->workspaceDirectory.'/.env.harbour'));
        self::assertStringContainsString('"redis_client":"predis"', Artisan::output());
    }

    public function test_a_composer_failure_prints_an_exact_selection_preserving_retry_command(): void
    {
        unlink($this->workspaceDirectory.'/.env.harbour');
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\n    \"name\": \"acme/app\"\n}\n");
        $this->application()->instance(InstallationPreflight::class, new RemediableInstallationPreflight);
        $this->application()->instance(InstallationDependencyInstaller::class, new FailingInstallationDependencyInstaller);

        self::assertSame(1, Artisan::call('workspace:install', [
            '--database' => 'sqlite',
            '--cache' => 'redis',
            '--mail' => 'log',
            '--with' => 'meilisearch',
            '--provider' => 'shared',
            '--install-dependencies' => true,
            '--start' => true,
        ]));

        $output = Artisan::output();
        self::assertStringContainsString('Composer dependency conflict', $output);
        self::assertStringContainsString('Retry without repeating your choices', $output);
        self::assertStringContainsString('--database=sqlite --cache=redis --mail=log --with=meilisearch --provider=shared --redis-client=predis --install-dependencies --start', $output);
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
        $tester->setInputs(['Auto-detect from this project', 'yes', 'no']);

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('Harbour detected this project configuration.', $tester->getDisplay());
        self::assertStringContainsString('Detected from', $tester->getDisplay());
        self::assertStringContainsString('REDIS_PORT=16379', (string) file_get_contents($this->workspaceDirectory.'/.env.harbour'));
        self::assertNull($this->application()->make(WorkspaceManager::class)->current());
    }

    public function test_interactive_detection_honours_the_start_switch(): void
    {
        unlink($this->workspaceDirectory.'/.env.harbour');
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\n    \"name\": \"acme/app\"\n}\n");
        $starter = new FakeInstalledWorkspaceStarter;
        $launcher = new FakeInstalledApplicationLauncher;
        $this->application()->instance(InstalledWorkspaceStarter::class, $starter);
        $this->application()->instance(InstalledApplicationLauncher::class, $launcher);

        $command = $this->application()->make(InstallCommand::class);
        $command->setLaravel($this->application());
        $tester = new CommandTester($command);
        $tester->setInputs(['Auto-detect from this project', 'yes', 'yes']);

        self::assertSame(0, $tester->execute(['--start' => true]), $tester->getDisplay());
        self::assertTrue($starter->started);
        self::assertTrue($launcher->launched);
        self::assertStringContainsString('test-workspace', $tester->getDisplay());
        self::assertStringContainsString('harbour-test-workspace', $tester->getDisplay());
        self::assertStringContainsString('Launching Laravel and Vite', $tester->getDisplay());
        self::assertStringNotContainsString('php artisan serve', $tester->getDisplay());
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

    public function test_compose_switch_generates_managed_service_configuration(): void
    {
        unlink($this->workspaceDirectory.'/.env.harbour');
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\n    \"name\": \"acme/app\"\n}\n");

        self::assertSame(0, Artisan::call('workspace:install', [
            '--database' => 'postgresql',
            '--cache' => 'redis',
            '--mail' => 'mailpit',
            '--with' => 'meilisearch,minio',
            '--compose' => true,
            '--json' => true,
        ]));

        $output = Artisan::output();
        self::assertStringContainsString('"provider":"compose"', $output);
        self::assertStringContainsString('docker-compose.harbour.yml', $output);
        self::assertFileExists($this->workspaceDirectory.'/docker-compose.harbour.yml');
    }

    public function test_compose_provider_requires_a_service_backed_selection(): void
    {
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\n    \"name\": \"acme/app\"\n}\n");

        self::assertSame(1, Artisan::call('workspace:install', [
            '--database' => 'sqlite',
            '--cache' => 'file',
            '--mail' => 'log',
            '--with' => 'none',
            '--compose' => true,
            '--json' => true,
        ]));

        self::assertStringContainsString('INVALID_INSTALL_SELECTION', Artisan::output());
        self::assertFileDoesNotExist($this->workspaceDirectory.'/docker-compose.harbour.yml');
    }

    public function test_invalid_provider_is_rejected_before_interactive_selection(): void
    {
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\n    \"name\": \"acme/app\"\n}\n");

        self::assertSame(1, Artisan::call('workspace:install', [
            '--database' => 'pgsql',
            '--provider' => 'remote',
            '--json' => true,
        ]));

        self::assertStringContainsString('INVALID_INSTALL_SELECTION', Artisan::output());
        self::assertFileDoesNotExist($this->workspaceDirectory.'/config/harbour.php');
    }

    public function test_start_switch_sets_up_the_workspace_after_installation(): void
    {
        unlink($this->workspaceDirectory.'/.env.harbour');
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\n    \"name\": \"acme/app\"\n}\n");
        $starter = new FakeInstalledWorkspaceStarter;
        $this->application()->instance(InstalledWorkspaceStarter::class, $starter);

        self::assertSame(0, Artisan::call('workspace:install', [
            '--database' => 'sqlite',
            '--cache' => 'file',
            '--mail' => 'log',
            '--with' => 'none',
            '--start' => true,
            '--json' => true,
        ]));

        self::assertTrue($starter->started);
        $output = Artisan::output();
        self::assertStringContainsString('"started":true', $output);
        self::assertStringContainsString('"slug":"test-workspace"', $output);
    }

    public function test_launch_switch_implies_setup_and_json_rejects_an_attached_session(): void
    {
        unlink($this->workspaceDirectory.'/.env.harbour');
        file_put_contents($this->workspaceDirectory.'/composer.json', "{\n    \"name\": \"acme/app\"\n}\n");
        $starter = new FakeInstalledWorkspaceStarter;
        $launcher = new FakeInstalledApplicationLauncher;
        $this->application()->instance(InstalledWorkspaceStarter::class, $starter);
        $this->application()->instance(InstalledApplicationLauncher::class, $launcher);
        $options = [
            '--database' => 'sqlite',
            '--cache' => 'file',
            '--mail' => 'log',
            '--with' => 'none',
            '--launch' => true,
        ];

        self::assertSame(0, Artisan::call('workspace:install', $options));
        self::assertTrue($starter->started);
        self::assertTrue($launcher->launched);

        self::assertSame(1, Artisan::call('workspace:install', [...$options, '--json' => true]));
        self::assertStringContainsString('INVALID_INSTALL_SELECTION', Artisan::output());
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

    public function test_workspace_dev_sets_up_and_launches_the_application_without_a_manual_serve_step(): void
    {
        $launcher = new FakeApplicationLauncher;
        $this->application()->instance(ApplicationLauncher::class, $launcher);

        self::assertSame(0, Artisan::call('workspace:dev', ['--no-vite' => true]));

        self::assertTrue($launcher->launched);
        self::assertFalse($launcher->vite);
        $output = Artisan::output();
        self::assertStringContainsString('Launching the application', $output);
        self::assertStringContainsString('Ctrl+C', $output);
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

    public function test_setup_and_render_require_force_before_replacing_a_modified_environment(): void
    {
        $manager = $this->application()->make(WorkspaceManager::class);
        $manager->setup();
        file_put_contents($this->workspaceDirectory.'/.env', "MANUAL=true\n");

        self::assertSame(1, Artisan::call('workspace:setup', ['--json' => true]));
        self::assertStringContainsString('HARBOUR_ENVIRONMENT_MODIFIED', Artisan::output());
        self::assertSame("MANUAL=true\n", file_get_contents($this->workspaceDirectory.'/.env'));

        self::assertSame(1, Artisan::call('workspace:render', ['--json' => true]));
        self::assertStringContainsString('HARBOUR_ENVIRONMENT_MODIFIED', Artisan::output());
        self::assertSame(0, Artisan::call('workspace:render', ['--force' => true, '--json' => true]));
        self::assertNotSame("MANUAL=true\n", file_get_contents($this->workspaceDirectory.'/.env'));

        $manager->teardown(true);
        self::assertSame("ORIGINAL=yes\n", file_get_contents($this->workspaceDirectory.'/.env'));
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

    public function test_non_interactive_destructive_commands_require_force(): void
    {
        $manager = $this->application()->make(WorkspaceManager::class);
        $manager->setup();

        self::assertSame(1, Artisan::call('workspace:setup', ['--fresh' => true, '--no-interaction' => true]));
        self::assertStringContainsString('--force', Artisan::output());
        self::assertNotNull($manager->current());

        self::assertSame(1, Artisan::call('workspace:teardown', ['--no-interaction' => true]));
        self::assertStringContainsString('--force', Artisan::output());
        self::assertNotNull($manager->current());

        $manager->teardown(true);
    }

    public function test_process_stderr_is_visible_in_human_and_json_failures(): void
    {
        file_put_contents($this->workspaceDirectory.'/compose.yml', "services: {}\n");
        $this->application()->make(Repository::class)->set('harbour.compose', [
            'test' => ['file' => 'compose.yml'],
        ]);
        $this->application()->instance(CommandRunner::class, new CommandFailureRunner);
        $this->application()->forgetInstance(ComposeManager::class);
        $this->application()->forgetInstance(WorkspaceManager::class);

        self::assertSame(1, Artisan::call('workspace:setup'));
        self::assertStringContainsString('compose healthcheck failed', Artisan::output());

        self::assertSame(1, Artisan::call('workspace:setup', ['--json' => true]));
        $output = Artisan::output();
        self::assertStringContainsString('"stderr":"compose healthcheck failed"', $output);
        self::assertStringContainsString('HARBOUR_COMPOSE_START_FAILED', $output);

        $this->application()->make(WorkspaceStateRepository::class)->delete();
    }

    public function test_setup_names_the_managed_compose_project_while_explaining_image_pulls(): void
    {
        file_put_contents($this->workspaceDirectory.'/compose.yml', "services: {}\n");
        $this->application()->make(Repository::class)->set('harbour.compose', [
            'test' => ['file' => 'compose.yml'],
        ]);
        $this->application()->instance(CommandRunner::class, new SuccessfulCommandRunner);
        $this->application()->forgetInstance(ComposeManager::class);
        $this->application()->forgetInstance(WorkspaceManager::class);

        self::assertSame(0, Artisan::call('workspace:setup'));
        $output = Artisan::output();
        self::assertStringContainsString('images will be pulled when missing', $output);
        self::assertStringContainsString('Compose project', $output);
        self::assertMatchesRegularExpression('/test-[a-z0-9-]+/', $output);
    }

    public function test_setup_warns_when_configuration_overrides_an_allocated_port(): void
    {
        $this->application()->make(Repository::class)->set('harbour.variables.APP_PORT', 19999);

        self::assertSame(0, Artisan::call('workspace:setup'));
        self::assertStringContainsString('overrides Harbour', Artisan::output());

        self::assertSame(0, Artisan::call('workspace:status', ['--json' => true]));
        self::assertStringContainsString('"warnings":["Configured variable [APP_PORT]=19999', Artisan::output());

        $this->application()->make(WorkspaceManager::class)->teardown(true);
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

final class FakeInstalledWorkspaceStarter implements InstalledWorkspaceStarter
{
    public bool $started = false;

    public function start(?callable $output = null): string
    {
        $this->started = true;

        return '{"version":1,"ok":true,"workspace":{"slug":"test-workspace","application_url":"http://127.0.0.1:8123","status":"ready","ports":{"APP_PORT":8123},"resources":[{"type":"compose_project","metadata":{"project_name":"harbour-test-workspace"}}]}}';
    }
}

final class FakeInstalledApplicationLauncher implements InstalledApplicationLauncher
{
    public bool $launched = false;

    public function launch(bool $vite = true, ?callable $output = null): void
    {
        $this->launched = true;
    }
}

final class FakeApplicationLauncher implements ApplicationLauncher
{
    public bool $launched = false;

    public bool $vite = true;

    public function launch(Workspace $workspace, bool $vite = true, ?callable $output = null): int
    {
        $this->launched = true;
        $this->vite = $vite;

        return 0;
    }
}

final class RemediableInstallationPreflight implements InstallationPreflight
{
    public bool $ready = false;

    public function requirements(InstallationSelection $selection): array
    {
        if ($this->ready) {
            return [];
        }

        return [
            new InstallationRequirement('package:predis/predis', 'Predis', 'Redis', 'composer require predis/predis'),
            new InstallationRequirement('package:laravel/scout', 'Scout', 'Meilisearch', 'composer require laravel/scout'),
        ];
    }

    public function assertReady(InstallationSelection $selection): void
    {
        if (! $this->ready) {
            throw new RuntimeException('Dependencies were not installed.');
        }
    }
}

final class FakeInstallationDependencyInstaller implements InstallationDependencyInstaller
{
    public bool $installed = false;

    /** @var list<InstallationRequirement> */
    public array $requirements = [];

    public function __construct(private readonly RemediableInstallationPreflight $preflight) {}

    public function install(array $requirements, ?callable $output = null): void
    {
        $this->installed = true;
        $this->requirements = $requirements;
        $this->preflight->ready = true;
    }
}

final class FailingInstallationDependencyInstaller implements InstallationDependencyInstaller
{
    public function install(array $requirements, ?callable $output = null): void
    {
        throw new HarbourException(ErrorCode::ProcessFailed, 'Composer dependency conflict');
    }
}

final class CommandFailureRunner implements CommandRunner
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function run(array $command, string $workingDirectory, array $environment = [], ?callable $output = null): ProcessResult
    {
        return new ProcessResult(1, '', 'compose healthcheck failed');
    }
}

final class SuccessfulCommandRunner implements CommandRunner
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function run(array $command, string $workingDirectory, array $environment = [], ?callable $output = null): ProcessResult
    {
        return new ProcessResult(0, '');
    }
}

final class ExplodingIdentityStrategy implements WorkspaceIdentityStrategy
{
    public function resolve(WorkspaceContext $context): WorkspaceIdentity
    {
        throw new RuntimeException('identity exploded');
    }
}
