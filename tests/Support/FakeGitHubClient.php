<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Support;

use PickeringTech\Harbour\Release\GitHubClient;
use PickeringTech\Harbour\Release\GitHubConflict;
use PickeringTech\Harbour\Release\GitHubRelease;
use PickeringTech\Harbour\Release\ReleaseEntry;
use PickeringTech\Harbour\Release\ReleaseTag;

final class FakeGitHubClient implements GitHubClient
{
    /** @var array<string, ReleaseTag> */
    public array $tags = [];

    /** @var array<string, GitHubRelease> */
    public array $releases = [];

    /** @var array<string, array<string, string>> */
    public array $checks = [];

    /** @var list<string> */
    public array $operations = [];

    public bool $immutableEnabled = true;

    public bool $createdTagVerified = true;

    public bool $publishedReleaseImmutable = true;

    public bool $conflictOnReference = false;

    public bool $conflictOnRelease = false;

    public ?ReleaseTag $concurrentTag = null;

    public ?GitHubRelease $concurrentRelease = null;

    public function tag(string $version): ?ReleaseTag
    {
        return $this->tags[$version] ?? null;
    }

    public function release(string $version): ?GitHubRelease
    {
        return $this->releases[$version] ?? null;
    }

    public function checks(string $commit): array
    {
        return $this->checks[$commit] ?? [];
    }

    public function immutableReleasesEnabled(): bool
    {
        $this->operations[] = 'check-immutability';

        return $this->immutableEnabled;
    }

    public function createTagObject(ReleaseEntry $entry): ReleaseTag
    {
        $this->operations[] = 'create-tag-object:'.$entry->version;

        return new ReleaseTag(
            $entry->version,
            $entry->commit,
            str_repeat('a', 40),
            true,
            $this->createdTagVerified,
            $this->createdTagVerified ? 'valid' : 'unsigned',
        );
    }

    public function createTagReference(ReleaseTag $tag): bool
    {
        $this->operations[] = 'create-tag-ref:'.$tag->version;
        if ($this->conflictOnReference) {
            $this->conflictOnReference = false;
            if ($this->concurrentTag !== null) {
                $this->tags[$tag->version] = $this->concurrentTag;
            }

            return false;
        }
        $this->tags[$tag->version] = $tag;

        return true;
    }

    public function createDraftRelease(ReleaseEntry $entry, string $notes): GitHubRelease
    {
        $this->operations[] = 'create-draft:'.$entry->version;
        if ($this->conflictOnRelease) {
            $this->conflictOnRelease = false;
            if ($this->concurrentRelease !== null) {
                $this->releases[$entry->version] = $this->concurrentRelease;
            }
            throw new GitHubConflict('concurrent release');
        }
        $release = new GitHubRelease(count($this->releases) + 1, $entry->version, true, false);
        $this->releases[$entry->version] = $release;

        return $release;
    }

    public function publishRelease(GitHubRelease $release): GitHubRelease
    {
        $this->operations[] = 'publish:'.$release->tagName;
        $published = new GitHubRelease($release->id, $release->tagName, false, $this->publishedReleaseImmutable);
        $this->releases[$release->tagName] = $published;

        return $published;
    }
}
