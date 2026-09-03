<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Docker\DockerManager;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Identity\ContextIdentifier;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;
use PickeringTech\Harbour\Process\ProcessResult;

final class DockerManagerTest extends TestCase
{
    public function test_it_creates_with_labels_and_only_removes_a_label_verified_container(): void
    {
        $runner = new FakeCommandRunner;
        $manager = new DockerManager($runner, new ContextIdentifier);
        $identity = new WorkspaceIdentity('ws_test', 'test-a1b2c3d4', str_repeat('a', 64), 'main');
        $resource = $manager->prepare($identity, 'search');
        $resource = $manager->create($resource, '/tmp', [
            'image' => 'getmeili/meilisearch:v1.20',
            'ports' => ['SEARCH_PORT' => ['container' => 7700]],
            'environment' => ['MEILI_ENV' => 'development'],
            'command' => ['meilisearch', '--http-addr', '0.0.0.0:7700'],
        ], ['SEARCH_PORT' => 11900]);

        $runner->labels = [
            DockerManager::MANAGED_LABEL => 'true',
            DockerManager::WORKSPACE_LABEL => 'ws_test',
            DockerManager::RESOURCE_LABEL => $resource->id,
        ];
        $manager->start($resource, '/tmp');
        $manager->destroy($resource, '/tmp');

        self::assertMatchesRegularExpression('/\Adocker_[a-f0-9]{32}\z/', $resource->id);
        self::assertSame('ws_test', $resource->workspaceId);
        self::assertSame('docker_container', $resource->type);
        self::assertSame('docker', $resource->driver);
        self::assertSame([
            'service' => 'search',
            'container_id' => 'container-id',
            'container_name' => 'search-test-a1b2c3d4-4aed2a46',
        ], $resource->metadata);
        self::assertSame([
            'docker', 'create', '--name', 'search-test-a1b2c3d4-4aed2a46',
            '--label', 'dev.harbour.managed=true',
            '--label', 'dev.harbour.workspace=ws_test',
            '--label', 'dev.harbour.resource='.$resource->id,
            '--publish', '127.0.0.1:11900:7700',
            '--env', 'MEILI_ENV=development',
            '--', 'getmeili/meilisearch:v1.20',
            'meilisearch', '--http-addr', '0.0.0.0:7700',
        ], $runner->commands[0]);
        $lastCommand = end($runner->commands);
        self::assertSame(['docker', 'rm', '--force', 'container-id'], $lastCommand);
    }

    public function test_it_refuses_to_remove_a_container_without_matching_labels(): void
    {
        $runner = new FakeCommandRunner;
        $manager = new DockerManager($runner, new ContextIdentifier);
        $identity = new WorkspaceIdentity('ws_test', 'test-a1b2c3d4', str_repeat('a', 64), 'main');
        $resource = $manager->prepare($identity, 'search');
        $resource = $manager->create($resource, '/tmp', ['image' => 'image'], []);
        $runner->labels = [DockerManager::MANAGED_LABEL => 'false'];

        $this->expectException(HarbourException::class);
        $manager->destroy($resource, '/tmp');
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, int>  $ports
     */
    #[DataProvider('invalidConfigurations')]
    public function test_it_rejects_each_unsafe_docker_configuration(array $configuration, array $ports = []): void
    {
        $manager = new DockerManager(new FakeCommandRunner, new ContextIdentifier);
        $resource = $manager->prepare(new WorkspaceIdentity('ws_test', 'test-a1b2c3d4', str_repeat('a', 64), 'main'), 'search');

        $this->expectException(HarbourException::class);
        $manager->create($resource, '/tmp', $configuration, $ports);
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, int>}> */
    public static function invalidConfigurations(): iterable
    {
        yield 'missing image' => [[], []];
        yield 'blank image' => [['image' => '   '], []];
        yield 'multiline image' => [['image' => "safe\n--privileged"], []];
        yield 'missing allocation' => [['image' => 'image', 'ports' => ['SEARCH_PORT' => 7700]], []];
        yield 'zero container port' => [['image' => 'image', 'ports' => ['SEARCH_PORT' => 0]], ['SEARCH_PORT' => 11900]];
        yield 'large container port' => [['image' => 'image', 'ports' => ['SEARCH_PORT' => 65536]], ['SEARCH_PORT' => 11900]];
        yield 'unsafe environment name' => [['image' => 'image', 'environment' => ['BAD-NAME' => 'value']], []];
        yield 'non scalar environment' => [['image' => 'image', 'environment' => ['VALUE' => []]], []];
        yield 'non string command' => [['image' => 'image', 'command' => [123]], []];
    }
}

final class FakeCommandRunner implements CommandRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    /** @var array<string, string> */
    public array $labels = [];

    public function run(array $command, string $workingDirectory, array $environment = []): ProcessResult
    {
        $this->commands[] = $command;

        if ($command[1] === 'create') {
            return new ProcessResult(0, 'container-id');
        }
        if ($command[1] === 'inspect' && in_array('--format', $command, true)) {
            return new ProcessResult(0, (string) json_encode($this->labels));
        }

        return new ProcessResult(0, 'ok');
    }
}
