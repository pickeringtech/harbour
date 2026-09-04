<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Release;

interface ReleaseRepository
{
    public function assertCommit(string $commit): void;

    public function assertReachableFrom(string $commit, string $mainRef): void;

    public function fileAt(string $commit, string $path): string;

    public function manifestAt(string $commit, string $path): ?Manifest;

    public function mergeBase(string $left, string $right): string;
}
