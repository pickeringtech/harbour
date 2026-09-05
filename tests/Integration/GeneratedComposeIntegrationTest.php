<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Integration;

use Illuminate\Contracts\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PickeringTech\Harbour\Installation\InstallationComposeRenderer;
use PickeringTech\Harbour\Installation\InstallationFileRenderer;
use PickeringTech\Harbour\Installation\InstallationSelection;
use PickeringTech\Harbour\Installation\InstallationServiceCatalog;
use PickeringTech\Harbour\Process\SymfonyCommandRunner;
use PickeringTech\Harbour\State\ResourceType;
use PickeringTech\Harbour\Tests\TestCase;
use PickeringTech\Harbour\WorkspaceManager;

final class GeneratedComposeIntegrationTest extends TestCase
{
    public function test_every_generated_service_is_valid_docker_compose_yaml(): void
    {
        if (getenv('HARBOUR_DOCKER_INTEGRATION') !== '1') {
            self::markTestSkipped('Set HARBOUR_DOCKER_INTEGRATION=1 to use Docker Compose.');
        }

        $catalog = new InstallationServiceCatalog;
        $renderer = new InstallationComposeRenderer($catalog);
        $runner = new SymfonyCommandRunner;

        foreach ($catalog->names() as $service) {
            $selection = InstallationSelection::fromOptions(null, null, null, $service, 'compose');
            $file = $this->workspaceDirectory.'/docker-compose.harbour.yml';
            file_put_contents($file, $renderer->render($selection));
            $environment = [];
            $port = 31000;
            foreach (array_keys($catalog->portDefinitions($selection->services())) as $variable) {
                $environment[$variable] = (string) $port++;
            }

            $result = $runner->run(['docker', 'compose', '--file', $file, 'config', '--quiet'], $this->workspaceDirectory, $environment);
            self::assertTrue($result->successful(), "{$service}: {$result->errorOutput}");
        }
    }

    public function test_generated_compose_services_start_on_allocated_ports_and_teardown_cleanly(): void
    {
        if (getenv('HARBOUR_DOCKER_INTEGRATION') !== '1') {
            self::markTestSkipped('Set HARBOUR_DOCKER_INTEGRATION=1 to mutate the local Docker daemon.');
        }

        $selection = InstallationSelection::fromOptions('none', 'redis', 'mailpit', 'none', 'compose');
        $catalog = new InstallationServiceCatalog;
        file_put_contents(
            $this->workspaceDirectory.'/docker-compose.harbour.yml',
            (new InstallationComposeRenderer($catalog))->render($selection),
        );
        file_put_contents(
            $this->workspaceDirectory.'/.env.harbour',
            (new InstallationFileRenderer($catalog))->environment($selection),
        );

        $config = $this->application()->make(Repository::class);
        $config->set('harbour.database.enabled', false);
        $config->set('harbour.ports.allocations', [
            'APP_PORT' => ['range' => [18200, 18299]],
            'VITE_PORT' => ['range' => [18300, 18399]],
            'REVERB_PORT' => ['range' => [18400, 18499]],
            ...$catalog->portDefinitions($selection->services()),
        ]);
        $config->set('harbour.compose', [
            'services' => [
                'file' => 'docker-compose.harbour.yml',
                'ports' => $catalog->portDefinitions($selection->services()),
            ],
        ]);
        $this->application()->forgetInstance(WorkspaceManager::class);
        $manager = $this->application()->make(WorkspaceManager::class);

        try {
            $workspace = $manager->setup();
            $redisPort = $workspace->ports()['REDIS_PORT'] ?? null;
            $mailPort = $workspace->ports()['MAIL_PORT'] ?? null;

            self::assertIsInt($redisPort);
            self::assertIsInt($mailPort);
            $this->assertPortOpen($redisPort);
            $this->assertPortOpen($mailPort);
            self::assertCount(1, array_filter(
                $workspace->state()->resources,
                static fn ($resource): bool => $resource->type === ResourceType::ComposeProject,
            ));

            $repeated = $manager->setup();
            self::assertSame($workspace->ports(), $repeated->ports());
            $this->assertPortOpen($redisPort);
            $this->assertPortOpen($mailPort);
        } finally {
            $manager->teardown(true);
        }

        self::assertNull($manager->current());
    }

    public function test_generated_compose_wait_reports_a_failed_healthcheck(): void
    {
        if (getenv('HARBOUR_DOCKER_INTEGRATION') !== '1') {
            self::markTestSkipped('Set HARBOUR_DOCKER_INTEGRATION=1 to mutate the local Docker daemon.');
        }

        $selection = InstallationSelection::fromOptions('none', 'none', 'mailpit', 'none', 'compose');
        $contents = (new InstallationComposeRenderer)->render($selection);
        $contents = str_replace(
            <<<'YAML'
      test: ["CMD", "wget", "--quiet", "--spider", "http://127.0.0.1:8025/readyz"]
      retries: 10
      timeout: 5s
YAML,
            <<<'YAML'
      test: ["CMD-SHELL", "exit 1"]
      interval: 100ms
      start_interval: 100ms
      start_period: 1ms
      retries: 1
      timeout: 1s
YAML,
            $contents,
        );
        $file = $this->workspaceDirectory.'/docker-compose.harbour.yml';
        file_put_contents($file, $contents);
        $project = 'harbour-health-'.bin2hex(random_bytes(4));
        $runner = new SymfonyCommandRunner;
        $environment = [
            'MAIL_PORT' => (string) $this->availablePort(),
            'MAILPIT_DASHBOARD_PORT' => (string) $this->availablePort(),
        ];
        $base = ['docker', 'compose', '--project-name', $project, '--file', $file];

        try {
            $started = $runner->run([...$base, 'up', '--detach'], $this->workspaceDirectory, $environment);
            self::assertTrue($started->successful(), $started->errorOutput);
            $container = $runner->run([...$base, 'ps', '--quiet', 'mailpit'], $this->workspaceDirectory, $environment);
            self::assertTrue($container->successful(), $container->errorOutput);
            self::assertNotSame('', $container->output);

            $health = '';
            $deadline = microtime(true) + 10;
            do {
                $inspection = $runner->run(
                    ['docker', 'inspect', '--format', '{{.State.Health.Status}}', $container->output],
                    $this->workspaceDirectory,
                );
                $health = $inspection->output;
                if ($health === 'unhealthy') {
                    break;
                }
                usleep(100_000);
            } while (microtime(true) < $deadline);
            self::assertSame('unhealthy', $health);

            $result = $runner->run([...$base, 'up', '--detach', '--wait', '--wait-timeout', '5'], $this->workspaceDirectory, $environment);
            self::assertFalse($result->successful());
            self::assertNotSame('', $result->errorOutput);
        } finally {
            $runner->run([...$base, 'down', '--remove-orphans'], $this->workspaceDirectory, $environment);
        }
    }

    #[DataProvider('managedDatabases')]
    public function test_generated_database_is_ready_before_harbour_creates_the_workspace_database(
        string $databaseDriver,
        string $extension,
    ): void {
        if (getenv('HARBOUR_DOCKER_INTEGRATION') !== '1') {
            self::markTestSkipped('Set HARBOUR_DOCKER_INTEGRATION=1 to mutate the local Docker daemon.');
        }
        if (! extension_loaded($extension)) {
            self::markTestSkipped("The {$extension} extension is required.");
        }

        $selection = InstallationSelection::fromOptions($databaseDriver, 'file', 'log', 'none', 'compose');
        $catalog = new InstallationServiceCatalog;
        file_put_contents(
            $this->workspaceDirectory.'/docker-compose.harbour.yml',
            (new InstallationComposeRenderer($catalog))->render($selection),
        );
        file_put_contents(
            $this->workspaceDirectory.'/.env.harbour',
            (new InstallationFileRenderer($catalog))->environment($selection),
        );

        $config = $this->application()->make(Repository::class);
        $config->set('harbour.installation.provider', 'compose');
        $config->set('harbour.database.enabled', true);
        $config->set('harbour.database.connection', $databaseDriver);
        $config->set('harbour.database.migrate', false);
        $config->set('database.connections.'.$databaseDriver, [
            'driver' => $databaseDriver,
            'host' => 'invalid-before-compose-overlay',
            'port' => 1,
            'database' => 'postgres',
            'username' => 'invalid',
            'password' => 'invalid',
        ]);
        $config->set('harbour.ports.allocations', [
            'APP_PORT' => ['range' => [18200, 18299]],
            'VITE_PORT' => ['range' => [18300, 18399]],
            'REVERB_PORT' => ['range' => [18400, 18499]],
            ...$catalog->portDefinitions($selection->services()),
        ]);
        $config->set('harbour.compose', [
            'services' => [
                'file' => 'docker-compose.harbour.yml',
                'ports' => $catalog->portDefinitions($selection->services()),
            ],
        ]);
        $this->application()->forgetInstance(WorkspaceManager::class);
        $manager = $this->application()->make(WorkspaceManager::class);

        try {
            $workspace = $manager->setup();
            $database = $workspace->toArray()['database'] ?? null;
            $port = $workspace->ports()['DB_PORT'] ?? null;

            self::assertIsString($database);
            self::assertIsInt($port);
            $this->assertPortOpen($port);
            self::assertStringContainsString('DB_HOST=127.0.0.1', (string) file_get_contents($this->workspaceDirectory.'/.env'));
            self::assertStringContainsString("DB_PORT={$port}", (string) file_get_contents($this->workspaceDirectory.'/.env'));

            $repeated = $manager->setup();
            self::assertSame($port, $repeated->ports()['DB_PORT'] ?? null);
            self::assertSame($database, $repeated->toArray()['database'] ?? null);
            $this->assertPortOpen($port);
        } finally {
            $manager->teardown(true);
        }

        self::assertNull($manager->current());
    }

    /** @return iterable<string, array{string, string}> */
    public static function managedDatabases(): iterable
    {
        yield 'PostgreSQL' => ['pgsql', 'pdo_pgsql'];
        yield 'MySQL' => ['mysql', 'pdo_mysql'];
        yield 'MariaDB' => ['mariadb', 'pdo_mysql'];
    }

    private function assertPortOpen(int $port): void
    {
        $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 2);
        if (! is_resource($socket)) {
            self::fail("Port {$port} did not accept a connection: {$errorCode} {$errorMessage}");
        }
        fclose($socket);
        self::addToAssertionCount(1);
    }

    private function availablePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage ?? 'Unable to find a free port.');
        $address = stream_socket_get_name($socket, false);
        if (! is_string($address) || ($separator = strrchr($address, ':')) === false) {
            self::fail('Unable to determine the free port.');
        }
        fclose($socket);

        return (int) substr($separator, 1);
    }
}
