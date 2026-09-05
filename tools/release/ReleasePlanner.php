<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Release;

final readonly class ReleasePlanner
{
    public function __construct(private ReleaseRepository $git) {}

    public function assertIntentTransition(
        Manifest $baseManifest,
        ?ReleaseIntent $baseIntent,
        ReleaseIntent $intent,
    ): void {
        $latest = $baseManifest->latest();

        if ($baseIntent === null) {
            if ($latest === null || $intent->version !== $latest->version) {
                throw new ReleaseException('The initial release intent must match the latest ledger entry.');
            }

            return;
        }

        if ($baseIntent->version !== $intent->version
            && ($latest === null || $baseIntent->version !== $latest->version)) {
            throw new ReleaseException(
                "Pending release intent {$baseIntent->version} cannot be replaced before it is recorded.",
            );
        }
    }

    public function pendingEntry(Manifest $manifest, ReleaseIntent $intent, string $mainRef): ?ReleaseEntry
    {
        $latest = $manifest->latest();
        if ($latest !== null && $latest->version === $intent->version) {
            return null;
        }

        if ($manifest->hasVersion($intent->version)) {
            throw new ReleaseException("Release intent {$intent->version} is older than the latest ledger entry.");
        }

        $target = $this->git->latestFirstParentChange($mainRef, 'release-intent.json');
        $entry = new ReleaseEntry($intent->version, $target);
        $manifest->withAppended($entry);

        return $entry;
    }
}
