<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Docker;

use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Identity\ContextIdentifier;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;
use PickeringTech\Harbour\State\OwnedResource;
use PickeringTech\Harbour\Support\AtomicFile;
use PickeringTech\Harbour\Support\WorkspacePath;

final readonly class ComposeManager
{
    public function __construct(
        private CommandRunner $processes,
        private ContextIdentifier $identifiers,
        private AtomicFile $files = new AtomicFile,
    ) {}

    /** @param array<string, mixed> $configuration */
    public function prepare(WorkspaceIdentity $workspace, string $workspacePath, string $name, array $configuration): OwnedResource
    {
        $file = $configuration['file'] ?? null;
        if (! is_string($file) || $file === '') {
            throw new HarbourException(ErrorCode::InvalidConfiguration, "Compose service [{$name}] requires a file.");
        }
        $path = $this->safeFile($workspacePath, $file);
        $project = $this->identifiers->compose($workspace, $name);
        $resourceId = 'compose_'.bin2hex(random_bytes(16));
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new HarbourException(ErrorCode::UnsafeOperation, "Unable to snapshot Compose file [{$path}].");
        }
        $snapshot = $workspacePath.'/.harbour/compose/'.$resourceId.'.yml';
        WorkspacePath::assertSafe($workspacePath, $snapshot);
        $this->files->write($snapshot, $contents);

        return new OwnedResource($resourceId, $workspace->id(), 'compose_project', 'compose', [
            'name' => $name,
            'project_name' => $project,
            'file' => $snapshot,
            'source_file' => $path,
            'working_directory' => $workspacePath,
        ]);
    }

    /** @param array<string, string> $environment */
    public function start(OwnedResource $resource, string $workspacePath, array $environment): void
    {
        [$project, $file, $workingDirectory] = $this->evidence($resource, $workspacePath);
        $result = $this->processes->run($this->command($project, $file, $workingDirectory, ['up', '-d']), $workingDirectory, $environment);

        if (! $result->successful()) {
            throw new HarbourException(ErrorCode::ComposeStartFailed, 'Unable to start the Harbour Compose project.', ['exit_code' => $result->exitCode]);
        }
    }

    public function destroy(OwnedResource $resource, string $workspacePath): void
    {
        [$project, $file, $workingDirectory] = $this->evidence($resource, $workspacePath);
        $ps = $this->processes->run($this->command($project, $file, $workingDirectory, ['ps', '-q']), $workingDirectory);
        if (! $ps->successful()) {
            throw new HarbourException(ErrorCode::ProcessFailed, 'Unable to inspect a Harbour Compose project before removal.', ['exit_code' => $ps->exitCode]);
        }
        foreach (array_filter(preg_split('/\R/', $ps->output) ?: []) as $container) {
            $inspect = $this->processes->run(['docker', 'inspect', '--format', '{{ index .Config.Labels "com.docker.compose.project" }}', $container], $workingDirectory);
            if (! $inspect->successful() || trim($inspect->output) !== $project) {
                throw new HarbourException(ErrorCode::DockerResourceNotOwned, 'Compose container labels do not match the recorded project.');
            }
        }
        $down = $this->processes->run($this->command($project, $file, $workingDirectory, ['down', '--remove-orphans']), $workingDirectory);
        if (! $down->successful()) {
            throw new HarbourException(ErrorCode::ProcessFailed, 'Unable to remove a Harbour Compose project.', ['exit_code' => $down->exitCode]);
        }
        @unlink($file);
        $directory = dirname($file);
        if (is_dir($directory) && (scandir($directory) ?: []) === ['.', '..']) {
            @rmdir($directory);
        }
    }

    /** @return array{string, string, string} */
    private function evidence(OwnedResource $resource, string $workspacePath): array
    {
        $project = $resource->metadata['project_name'] ?? null;
        $file = $resource->metadata['file'] ?? null;
        $workingDirectory = $resource->metadata['working_directory'] ?? null;
        $expectedFile = preg_match('/^compose_[a-f0-9]{32}$/', $resource->id)
            ? realpath($workspacePath.'/.harbour/compose/'.$resource->id.'.yml')
            : false;
        if (! $resource->createdByHarbour || $resource->type !== 'compose_project'
            || ! is_string($project) || ! preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $project)
            || ! is_string($file) || ! is_file($file) || $expectedFile === false || realpath($file) !== $expectedFile
            || ! is_string($workingDirectory) || realpath($workingDirectory) !== realpath($workspacePath)) {
            throw new HarbourException(ErrorCode::DockerResourceNotOwned, 'Compose resource lacks valid Harbour ownership evidence.');
        }
        WorkspacePath::assertSafe($workspacePath, $file);

        return [$project, $file, $workingDirectory];
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private function command(string $project, string $file, string $workingDirectory, array $arguments): array
    {
        return ['docker', 'compose', '--project-name', $project, '--project-directory', $workingDirectory, '--file', $file, ...$arguments];
    }

    private function safeFile(string $workspacePath, string $file): string
    {
        $root = realpath($workspacePath);
        $candidate = realpath(str_starts_with($file, '/') ? $file : $workspacePath.'/'.$file);
        if ($root === false || $candidate === false || ! is_file($candidate)
            || ($candidate !== $root && ! str_starts_with($candidate, $root.DIRECTORY_SEPARATOR))) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'Compose file must be a regular file inside the workspace.');
        }

        return $candidate;
    }
}
