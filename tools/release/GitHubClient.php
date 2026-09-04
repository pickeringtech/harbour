<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Release;

interface GitHubClient
{
    public function tag(string $version): ?ReleaseTag;

    public function release(string $version): ?GitHubRelease;

    /** @return array<string, string> */
    public function checks(string $commit): array;

    public function immutableReleasesEnabled(): bool;

    public function createDraftRelease(ReleaseEntry $entry, string $notes): GitHubRelease;

    public function publishRelease(GitHubRelease $release): GitHubRelease;
}
