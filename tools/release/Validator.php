<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Release;

final readonly class Validator
{
    public function __construct(
        private ReleaseRepository $git,
        private GitHubClient $github,
        private string $mainRef,
    ) {
        if (preg_match('/^[A-Za-z0-9._\/-]+$/D', $mainRef) !== 1) {
            throw new ReleaseException('Main ref contains invalid characters.');
        }
    }

    public function validate(Manifest $manifest, ?Manifest $base = null): ValidatedManifest
    {
        $this->assertLegacySeed($manifest);
        if ($base !== null) {
            $manifest->assertAppendOnlyFrom($base);
        }

        $notes = [];
        foreach ($manifest->entries as $entry) {
            $this->git->assertCommit($entry->commit);
            $this->git->assertReachableFrom($entry->commit, $this->mainRef);
            $notes[$entry->version] = Changelog::notes($this->git->fileAt($entry->commit, 'CHANGELOG.md'), $entry);
        }

        foreach ($manifest->entriesAddedAfter($base) as $entry) {
            $this->assertRequiredChecks($entry);
        }

        return new ValidatedManifest($manifest, $notes);
    }

    public function assertRequiredChecks(ReleaseEntry $entry): void
    {
        ReleasePolicy::assertRequiredChecks($entry, $this->github->checks($entry->commit));
    }

    private function assertLegacySeed(Manifest $manifest): void
    {
        $expected = [];
        foreach (ReleasePolicy::LEGACY_RELEASES as $version => $commit) {
            $expected[] = new ReleaseEntry($version, $commit);
        }

        foreach ($expected as $index => $entry) {
            if (! isset($manifest->entries[$index]) || ! $manifest->entries[$index]->equals($entry)) {
                throw new ReleaseException('Release manifest must retain the exact v0.0.1-v0.0.3 historical seed.');
            }
        }
    }
}
