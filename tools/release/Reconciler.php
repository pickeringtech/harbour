<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Release;

use Closure;
use Throwable;

final readonly class Reconciler
{
    private Closure $pause;

    private TagPublisher $tagPublisher;

    public function __construct(
        private GitHubClient $github,
        private PackagistClient $packagist,
        private int $packagistAttempts = 12,
        ?Closure $pause = null,
        ?TagPublisher $tagPublisher = null,
    ) {
        if ($packagistAttempts < 1) {
            throw new ReleaseException('Packagist attempts must be positive.');
        }
        $this->pause = $pause ?? static function (): void {
            sleep(10);
        };
        $this->tagPublisher = $tagPublisher ?? ($github instanceof TagPublisher
            ? $github
            : throw new ReleaseException('A release tag publisher is required.'));
    }

    /** @return list<ReconciliationResult> */
    public function reconcile(ValidatedManifest $validated): array
    {
        $states = [];
        $errors = [];

        foreach ($validated->manifest->entries as $entry) {
            try {
                $state = new ReconciliationState($entry, $this->github->tag($entry->version), $this->github->release($entry->version));
                $states[] = $state;
                $error = $this->stateError($state);
                if ($error !== null) {
                    $errors[$entry->version] = $error;
                }
            } catch (Throwable $exception) {
                $states[] = new ReconciliationState($entry, null, null);
                $errors[$entry->version] = $exception->getMessage();
            }
        }

        if ($errors !== []) {
            return $this->preflightFailures($states, $errors);
        }

        foreach ($states as $state) {
            if (! $state->needsWrite() || ReleasePolicy::isLegacy($state->entry)) {
                continue;
            }

            try {
                ReleasePolicy::assertRequiredChecks($state->entry, $this->github->checks($state->entry->commit));
            } catch (Throwable $exception) {
                $errors[$state->entry->version] = $exception->getMessage();
            }
        }

        if ($errors !== []) {
            return $this->preflightFailures($states, $errors);
        }

        if ($this->hasWrites($states) && ! $this->github->immutableReleasesEnabled()) {
            return array_map(
                static fn (ReconciliationState $state): ReconciliationResult => new ReconciliationResult(
                    $state->entry,
                    'failed closed',
                    'GitHub immutable releases are not enabled; no writes were attempted.',
                    false,
                ),
                $states,
            );
        }

        $results = [];
        $aborted = false;
        foreach ($states as $state) {
            if ($aborted) {
                $results[] = new ReconciliationResult($state->entry, 'failed closed', 'Not attempted after an earlier reconciliation failure.', false);

                continue;
            }

            try {
                $results[] = $this->reconcileEntry($state, $validated);
            } catch (Throwable $exception) {
                $results[] = new ReconciliationResult($state->entry, 'failed closed', $exception->getMessage(), false);
                $aborted = true;
            }
        }

        return $results;
    }

    /**
     * @param  list<ReconciliationState>  $states
     * @param  array<string, string>  $errors
     * @return list<ReconciliationResult>
     */
    private function preflightFailures(array $states, array $errors): array
    {
        return array_map(
            static fn (ReconciliationState $state): ReconciliationResult => new ReconciliationResult(
                $state->entry,
                'failed closed',
                $errors[$state->entry->version] ?? 'Not attempted because another manifest entry failed preflight.',
                false,
            ),
            $states,
        );
    }

    /** @param list<ReconciliationState> $states */
    private function hasWrites(array $states): bool
    {
        foreach ($states as $state) {
            if ($state->needsWrite()) {
                return true;
            }
        }

        return false;
    }

    private function stateError(ReconciliationState $state): ?string
    {
        $entry = $state->entry;
        $tag = $state->tag;
        $release = $state->release;

        if ($release !== null && $tag === null) {
            return "Release {$entry->version} exists but its tag is missing.";
        }
        if ($release !== null && $release->tagName !== $entry->version) {
            return "Release {$entry->version} refers to tag {$release->tagName}.";
        }
        if ($tag === null) {
            return null;
        }
        if (! $tag->annotated) {
            return "Tag {$entry->version} is lightweight; existing tags are compare-only.";
        }
        if ($tag->version !== $entry->version) {
            return "Tag object for {$entry->version} declares {$tag->version}.";
        }
        if ($tag->commit !== $entry->commit) {
            return "Tag {$entry->version} points to {$tag->commit}, expected {$entry->commit}; it will not be moved.";
        }
        if (! ReleasePolicy::isLegacy($entry) && ! $tag->verified) {
            return "Tag {$entry->version} is not GitHub-verified ({$tag->verificationReason}).";
        }
        if ($release !== null && ! $release->draft && ! ReleasePolicy::isLegacy($entry) && ! $release->immutable) {
            return "Published release {$entry->version} is not immutable.";
        }

        return null;
    }

    private function reconcileEntry(ReconciliationState $state, ValidatedManifest $validated): ReconciliationResult
    {
        $entry = $state->entry;
        $tag = $state->tag;
        $release = $state->release;
        $tagCreated = false;
        $releasePublished = false;

        if (! $state->needsWrite()) {
            $packagist = $this->waitForPackagist($entry);

            return new ReconciliationResult(
                $entry,
                $packagist ? 'already synchronized' : 'failed closed',
                $packagist
                    ? "GitHub and Packagist resolve to {$entry->commit}."
                    : "GitHub is synchronized, but Packagist does not resolve {$entry->version} to {$entry->commit}.",
                $packagist,
            );
        }

        if ($tag === null) {
            $tag = $this->tagPublisher->createTagObject($entry);
            $this->assertCreatedTag($entry, $tag);
            $created = $this->tagPublisher->createTagReference($tag);

            $tag = $this->github->tag($entry->version);
            if ($tag === null) {
                throw new ReleaseException("Tag {$entry->version} ".($created ? 'was pushed' : 'conflicted during creation').' but cannot be re-read.');
            }
            $createdError = $this->stateError(new ReconciliationState($entry, $tag, null));
            if ($createdError !== null) {
                throw new ReleaseException(($created ? 'Created tag failed verification: ' : 'Concurrent tag creation was not exact: ').$createdError);
            }
            if ($created) {
                $tagCreated = true;
            }
        }

        if ($release === null) {
            try {
                $release = $this->github->createDraftRelease($entry, $validated->notesFor($entry));
            } catch (GitHubConflict) {
                $release = $this->github->release($entry->version);
                if ($release === null) {
                    throw new ReleaseException("Release {$entry->version} conflicted during creation but cannot be re-read.");
                }
            }
        }

        if ($release->tagName !== $entry->version) {
            throw new ReleaseException("Release {$entry->version} unexpectedly refers to tag {$release->tagName}.");
        }

        if ($release->draft) {
            $release = $this->github->publishRelease($release);
            $releasePublished = true;
        }
        if ($release->draft || $release->tagName !== $entry->version || ! $release->immutable) {
            throw new ReleaseException("Publishing {$entry->version} did not produce an immutable release.");
        }

        $packagist = $this->waitForPackagist($entry);
        $status = match (true) {
            $tagCreated && $releasePublished => 'tag created; release published',
            $releasePublished => 'release published',
            default => 'already synchronized',
        };
        $detail = $packagist
            ? "GitHub and Packagist resolve to {$entry->commit}."
            : "GitHub is synchronized, but Packagist does not resolve {$entry->version} to {$entry->commit}.";

        return new ReconciliationResult($entry, $packagist ? $status : 'failed closed', $detail, $packagist);
    }

    private function assertCreatedTag(ReleaseEntry $entry, ReleaseTag $tag): void
    {
        if (! $tag->annotated || $tag->version !== $entry->version || $tag->commit !== $entry->commit || ! $tag->verified) {
            throw new ReleaseException("GitHub did not create an exact verified annotated tag object for {$entry->version} ({$tag->verificationReason}); no ref was created.");
        }
    }

    private function waitForPackagist(ReleaseEntry $entry): bool
    {
        for ($attempt = 1; $attempt <= $this->packagistAttempts; $attempt++) {
            if ($this->packagist->resolvesTo($entry)) {
                return true;
            }
            if ($attempt < $this->packagistAttempts) {
                ($this->pause)();
            }
        }

        return false;
    }
}
