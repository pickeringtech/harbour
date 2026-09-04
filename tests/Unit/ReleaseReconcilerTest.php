<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Release\GitHubRelease;
use PickeringTech\Harbour\Release\Manifest;
use PickeringTech\Harbour\Release\Reconciler;
use PickeringTech\Harbour\Release\ReconciliationResult;
use PickeringTech\Harbour\Release\ReleaseEntry;
use PickeringTech\Harbour\Release\ReleasePolicy;
use PickeringTech\Harbour\Release\ReleaseTag;
use PickeringTech\Harbour\Release\ValidatedManifest;
use PickeringTech\Harbour\Tests\Support\FakeGitHubClient;
use PickeringTech\Harbour\Tests\Support\FakePackagistClient;

final class ReleaseReconcilerTest extends TestCase
{
    public function test_empty_state_creates_verified_tag_then_draft_then_immutable_release(): void
    {
        $entry = $this->entry('v1.0.0', 'a');
        $github = new FakeGitHubClient;
        $results = $this->reconcile($github, [$entry]);

        self::assertTrue($results[0]->successful);
        self::assertSame('tag created; release published', $results[0]->status);
        self::assertSame([
            'check-immutability',
            'create-tag-object:v1.0.0',
            'create-tag-ref:v1.0.0',
            'create-draft:v1.0.0',
            'publish:v1.0.0',
        ], $github->operations);
        self::assertTrue($github->tags['v1.0.0']->verified);
        self::assertTrue($github->releases['v1.0.0']->immutable);
    }

    public function test_exact_state_is_a_no_op_and_accepts_compare_only_legacy_history(): void
    {
        $entry = new ReleaseEntry('v0.0.1', 'b29047d0a593fe52221751af54761009b31b194f');
        $github = new FakeGitHubClient;
        $github->tags[$entry->version] = $this->tag($entry, verified: false);
        $github->releases[$entry->version] = new GitHubRelease(1, $entry->version, false, false);

        $results = $this->reconcile($github, [$entry]);

        self::assertTrue($results[0]->successful);
        self::assertSame('already synchronized', $results[0]->status);
        self::assertSame([], $github->operations);
    }

    public function test_multiple_missing_releases_are_processed_in_manifest_order(): void
    {
        $first = $this->entry('v1.0.0', 'a');
        $second = $this->entry('v1.1.0', 'b');
        $github = new FakeGitHubClient;

        $results = $this->reconcile($github, [$first, $second]);

        self::assertTrue($results[0]->successful);
        self::assertTrue($results[1]->successful);
        self::assertSame([
            'check-immutability',
            'create-tag-object:v1.0.0',
            'create-tag-ref:v1.0.0',
            'create-draft:v1.0.0',
            'publish:v1.0.0',
            'create-tag-object:v1.1.0',
            'create-tag-ref:v1.1.0',
            'create-draft:v1.1.0',
            'publish:v1.1.0',
        ], $github->operations);
    }

    public function test_exact_tag_with_missing_release_recovers_without_recreating_tag(): void
    {
        $entry = $this->entry('v1.0.0', 'a');
        $github = new FakeGitHubClient;
        $github->tags[$entry->version] = $this->tag($entry);

        $results = $this->reconcile($github, [$entry]);

        self::assertSame('release published', $results[0]->status);
        self::assertSame(['check-immutability', 'create-draft:v1.0.0', 'publish:v1.0.0'], $github->operations);
    }

    public function test_exact_tag_with_draft_release_publishes_that_draft(): void
    {
        $entry = $this->entry('v1.0.0', 'a');
        $github = new FakeGitHubClient;
        $github->tags[$entry->version] = $this->tag($entry);
        $github->releases[$entry->version] = new GitHubRelease(7, $entry->version, true, false);

        $results = $this->reconcile($github, [$entry]);

        self::assertSame('release published', $results[0]->status);
        self::assertSame(['check-immutability', 'publish:v1.0.0'], $github->operations);
        self::assertSame(7, $github->releases[$entry->version]->id);
    }

    #[DataProvider('unsafeStateProvider')]
    public function test_unsafe_existing_state_fails_before_any_write(string $kind, string $message): void
    {
        $entry = $this->entry('v1.0.0', 'a');
        $github = new FakeGitHubClient;

        match ($kind) {
            'mismatch' => $github->tags[$entry->version] = $this->tag(new ReleaseEntry($entry->version, str_repeat('b', 40))),
            'lightweight' => $github->tags[$entry->version] = new ReleaseTag($entry->version, $entry->commit, $entry->commit, false, false, 'lightweight'),
            'unverified' => $github->tags[$entry->version] = $this->tag($entry, verified: false),
            'missing-tag' => $github->releases[$entry->version] = new GitHubRelease(1, $entry->version, false, true),
            'wrong-release-tag' => [
                $github->tags[$entry->version] = $this->tag($entry),
                $github->releases[$entry->version] = new GitHubRelease(1, 'v2.0.0', false, true),
            ],
            'mutable-release' => [
                $github->tags[$entry->version] = $this->tag($entry),
                $github->releases[$entry->version] = new GitHubRelease(1, $entry->version, false, false),
            ],
            default => self::fail("Unknown unsafe state fixture {$kind}."),
        };

        $results = $this->reconcile($github, [$entry]);

        self::assertFalse($results[0]->successful);
        self::assertStringContainsString($message, $results[0]->detail);
        self::assertSame([], $github->operations);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsafeStateProvider(): iterable
    {
        yield 'mismatched commit' => ['mismatch', 'will not be moved'];
        yield 'lightweight tag' => ['lightweight', 'lightweight'];
        yield 'unverified tag' => ['unverified', 'not GitHub-verified'];
        yield 'release missing tag' => ['missing-tag', 'tag is missing'];
        yield 'wrong release tag' => ['wrong-release-tag', 'refers to tag'];
        yield 'mutable release' => ['mutable-release', 'not immutable'];
    }

    public function test_unverified_created_object_never_gets_a_reference(): void
    {
        $entry = $this->entry('v1.0.0', 'a');
        $github = new FakeGitHubClient;
        $github->createdTagVerified = false;

        $results = $this->reconcile($github, [$entry]);

        self::assertFalse($results[0]->successful);
        self::assertStringContainsString('no ref was created', $results[0]->detail);
        self::assertSame(['check-immutability', 'create-tag-object:v1.0.0'], $github->operations);
    }

    public function test_tag_is_refetched_and_must_be_github_verified_after_push(): void
    {
        $entry = $this->entry('v1.0.0', 'a');
        $github = new FakeGitHubClient;
        $github->publishedTagVerified = false;

        $results = $this->reconcile($github, [$entry]);

        self::assertFalse($results[0]->successful);
        self::assertStringContainsString('Created tag failed verification', $results[0]->detail);
        self::assertSame([
            'check-immutability',
            'create-tag-object:v1.0.0',
            'create-tag-ref:v1.0.0',
        ], $github->operations);
        self::assertArrayNotHasKey($entry->version, $github->releases);
    }

    public function test_concurrent_exact_ref_creation_is_re_read_and_accepted(): void
    {
        $entry = $this->entry('v1.0.0', 'a');
        $github = new FakeGitHubClient;
        $github->conflictOnReference = true;
        $github->concurrentTag = $this->tag($entry);

        $results = $this->reconcile($github, [$entry]);

        self::assertTrue($results[0]->successful);
        self::assertSame('release published', $results[0]->status);
    }

    public function test_concurrent_mismatched_ref_creation_fails_without_a_release(): void
    {
        $entry = $this->entry('v1.0.0', 'a');
        $github = new FakeGitHubClient;
        $github->conflictOnReference = true;
        $github->concurrentTag = $this->tag(new ReleaseEntry($entry->version, str_repeat('b', 40)));

        $results = $this->reconcile($github, [$entry]);

        self::assertFalse($results[0]->successful);
        self::assertStringContainsString('Concurrent tag creation was not exact', $results[0]->detail);
        self::assertArrayNotHasKey($entry->version, $github->releases);
    }

    public function test_failure_after_tag_creation_resumes_with_only_the_missing_release(): void
    {
        $entry = $this->entry('v1.0.0', 'a');
        $github = new FakeGitHubClient;
        $github->conflictOnRelease = true;

        $first = $this->reconcile($github, [$entry]);
        self::assertFalse($first[0]->successful);
        self::assertArrayHasKey($entry->version, $github->tags);
        self::assertArrayNotHasKey($entry->version, $github->releases);

        $github->operations = [];
        $second = $this->reconcile($github, [$entry]);

        self::assertTrue($second[0]->successful);
        self::assertSame(['check-immutability', 'create-draft:v1.0.0', 'publish:v1.0.0'], $github->operations);
    }

    public function test_disabled_immutable_releases_fail_before_writes(): void
    {
        $entry = $this->entry('v1.0.0', 'a');
        $github = new FakeGitHubClient;
        $github->immutableEnabled = false;

        $results = $this->reconcile($github, [$entry]);

        self::assertFalse($results[0]->successful);
        self::assertStringContainsString('not enabled', $results[0]->detail);
        self::assertSame(['check-immutability'], $github->operations);
    }

    public function test_missing_required_checks_fail_before_immutability_or_writes(): void
    {
        $entry = $this->entry('v1.0.0', 'a');
        $github = new FakeGitHubClient;
        $github->checks[$entry->commit] = array_fill_keys(ReleasePolicy::REQUIRED_CHECKS, 'success');
        $github->checks[$entry->commit]['Coverage'] = 'failure';

        $results = (new Reconciler($github, new FakePackagistClient, 1))->reconcile($this->validated([$entry]));

        self::assertFalse($results[0]->successful);
        self::assertStringContainsString('Coverage (failure)', $results[0]->detail);
        self::assertSame([], $github->operations);
    }

    public function test_packagist_is_retried_and_a_wrong_sha_fails_the_result(): void
    {
        $entry = $this->entry('v1.0.0', 'a');
        $github = new FakeGitHubClient;
        $github->tags[$entry->version] = $this->tag($entry);
        $github->releases[$entry->version] = new GitHubRelease(1, $entry->version, false, true);
        $packagist = new FakePackagistClient;
        $packagist->responses[$entry->version] = [false, false];
        $pauses = 0;
        $reconciler = new Reconciler($github, $packagist, 2, static function () use (&$pauses): void {
            $pauses++;
        });

        $results = $reconciler->reconcile($this->validated([$entry]));

        self::assertFalse($results[0]->successful);
        self::assertStringContainsString('does not resolve', $results[0]->detail);
        self::assertSame(1, $pauses);
        self::assertSame(['v1.0.0', 'v1.0.0'], $packagist->calls);
    }

    /**
     * @param  list<ReleaseEntry>  $entries
     * @return list<ReconciliationResult>
     */
    private function reconcile(FakeGitHubClient $github, array $entries): array
    {
        foreach ($entries as $entry) {
            if (! ReleasePolicy::isLegacy($entry) && ! isset($github->checks[$entry->commit])) {
                $github->checks[$entry->commit] = array_fill_keys(ReleasePolicy::REQUIRED_CHECKS, 'success');
            }
        }

        return (new Reconciler($github, new FakePackagistClient, 1))->reconcile($this->validated($entries));
    }

    /** @param list<ReleaseEntry> $entries */
    private function validated(array $entries): ValidatedManifest
    {
        $releases = [];
        $notes = [];
        foreach ($entries as $entry) {
            $releases[] = ['version' => $entry->version, 'commit' => $entry->commit];
            $notes[$entry->version] = '- Release notes.';
        }

        return new ValidatedManifest(
            Manifest::fromJson(json_encode(['schema' => 1, 'releases' => $releases], JSON_THROW_ON_ERROR)),
            $notes,
        );
    }

    private function entry(string $version, string $character): ReleaseEntry
    {
        return new ReleaseEntry($version, str_repeat($character, 40));
    }

    private function tag(ReleaseEntry $entry, bool $verified = true): ReleaseTag
    {
        return new ReleaseTag($entry->version, $entry->commit, str_repeat('f', 40), true, $verified, $verified ? 'valid' : 'unsigned');
    }
}
