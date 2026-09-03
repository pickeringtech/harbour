<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Docker\ComposeManager;
use PickeringTech\Harbour\Docker\DockerManager;
use PickeringTech\Harbour\Identity\ContextIdentifier;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;
use PickeringTech\Harbour\Process\SymfonyCommandRunner;

final class DockerIntegrationTest extends TestCase
{
    public function test_real_container_and_two_compose_projects_are_independent(): void
    {
        if (getenv('HARBOUR_DOCKER_INTEGRATION') !== '1') {
            self::markTestSkipped('Set HARBOUR_DOCKER_INTEGRATION=1 to mutate the local Docker daemon.');
        }
        $runner = new SymfonyCommandRunner;
        $identifiers = new ContextIdentifier;
        $docker = new DockerManager($runner, $identifiers);
        $first = $this->identity('a');
        $resource = $docker->prepare($first, 'sleeper');

        try {
            $resource = $docker->create($resource, dirname(__DIR__), ['image' => 'alpine:3.22', 'command' => ['sleep', '300']], []);
            $docker->start($resource, dirname(__DIR__));

            $compose = new ComposeManager($runner, $identifiers);
            $config = ['file' => 'Fixtures/docker-compose.harbour.yml'];
            $projectA = $compose->prepare($first, dirname(__DIR__), 'stack', $config);
            $projectB = $compose->prepare($this->identity('b'), dirname(__DIR__), 'stack', $config);
            self::assertNotSame($projectA->metadata['project_name'], $projectB->metadata['project_name']);
            $compose->start($projectA, dirname(__DIR__), []);
            $compose->start($projectB, dirname(__DIR__), []);
            $projectBName = $projectB->metadata['project_name'];
            $composeFile = $projectB->metadata['file'];
            self::assertIsString($projectBName);
            self::assertIsString($composeFile);
            $before = $runner->run(['docker', 'compose', '--project-name', $projectBName, '--file', $composeFile, 'ps', '-q'], dirname(__DIR__));
            self::assertTrue($before->successful());
            self::assertNotSame('', $before->output);
            $compose->destroy($projectA, dirname(__DIR__));
            $after = $runner->run(['docker', 'compose', '--project-name', $projectBName, '--file', $composeFile, 'ps', '-q'], dirname(__DIR__));
            self::assertSame($before->output, $after->output);
            $compose->destroy($projectB, dirname(__DIR__));
        } finally {
            $docker->destroy($resource, dirname(__DIR__));
        }
    }

    private function identity(string $suffix): WorkspaceIdentity
    {
        $hash = hash('sha256', $suffix.bin2hex(random_bytes(4)));

        return new WorkspaceIdentity('ws_'.$hash, 'docker-'.$suffix.'-'.substr($hash, 0, 8), $hash, 'docker/'.$suffix);
    }
}
