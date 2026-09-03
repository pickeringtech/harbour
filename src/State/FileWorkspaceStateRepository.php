<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\State;

use JsonException;
use PickeringTech\Harbour\Contracts\WorkspaceStateRepository;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Support\AtomicFile;
use Throwable;

final class FileWorkspaceStateRepository implements WorkspaceStateRepository
{
    public function __construct(
        private readonly string $path,
        private readonly AtomicFile $files = new AtomicFile,
    ) {}

    public function load(): ?WorkspaceState
    {
        $this->assertRegularOrMissing();

        if (! is_file($this->path)) {
            return null;
        }

        try {
            $contents = file_get_contents($this->path);

            if ($contents === false) {
                throw new JsonException('State is unreadable.');
            }

            $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($data)) {
                throw new JsonException('State root must be an object.');
            }

            return WorkspaceState::fromArray($data);
        } catch (Throwable $exception) {
            if ($exception instanceof HarbourException) {
                throw $exception;
            }

            throw new HarbourException(
                ErrorCode::StateCorrupted,
                'Harbour state is corrupted; it was not overwritten.',
                ['path' => $this->path],
                $exception,
            );
        }
    }

    public function save(WorkspaceState $state): void
    {
        $this->assertRegularOrMissing();

        try {
            $json = json_encode($state->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        } catch (JsonException $exception) {
            throw new HarbourException(ErrorCode::StateWriteFailed, 'Unable to encode Harbour state.', [], $exception);
        }

        $this->files->write($this->path, $json);
    }

    public function delete(): void
    {
        $this->assertRegularOrMissing();

        if (is_file($this->path) && ! @unlink($this->path)) {
            throw new HarbourException(ErrorCode::StateWriteFailed, "Unable to remove [{$this->path}].");
        }
    }

    private function assertRegularOrMissing(): void
    {
        if (is_link($this->path) || (file_exists($this->path) && ! is_file($this->path))) {
            throw new HarbourException(ErrorCode::UnsafeOperation, "Refusing to manage unsafe state path [{$this->path}].");
        }
    }
}
