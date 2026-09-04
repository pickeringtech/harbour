<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Support;

use PickeringTech\Harbour\Release\Manifest;
use PickeringTech\Harbour\Release\ReleaseException;
use PickeringTech\Harbour\Release\ReleaseRepository;

final class FakeReleaseRepository implements ReleaseRepository
{
    /** @var array<string, string> */
    public array $types = [];

    /** @var array<string, bool> */
    public array $reachable = [];

    /** @var array<string, array<string, string>> */
    public array $files = [];

    /** @var array<string, Manifest|null> */
    public array $manifests = [];

    public function assertCommit(string $commit): void
    {
        $type = $this->types[$commit] ?? null;
        if ($type === null) {
            throw new ReleaseException("Declared object {$commit} does not exist in the checkout.");
        }
        if ($type !== 'commit') {
            throw new ReleaseException("Declared object {$commit} is not a commit.");
        }
    }

    public function assertReachableFrom(string $commit, string $mainRef): void
    {
        if (($this->reachable[$commit] ?? false) !== true) {
            throw new ReleaseException("Declared commit {$commit} is not reachable from {$mainRef}.");
        }
    }

    public function fileAt(string $commit, string $path): string
    {
        if (! isset($this->files[$commit][$path])) {
            throw new ReleaseException("Repository file {$path} is absent at {$commit}.");
        }

        return $this->files[$commit][$path];
    }

    public function manifestAt(string $commit, string $path): ?Manifest
    {
        return $this->manifests[$commit] ?? null;
    }

    public function mergeBase(string $left, string $right): string
    {
        return $right;
    }
}
