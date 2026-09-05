<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Release;

use Throwable;

final class Command
{
    /** @param list<string> $arguments */
    public static function run(array $arguments, string $root): int
    {
        $command = $arguments[1] ?? null;

        try {
            return match ($command) {
                'validate' => self::validate($root),
                'validate-pr' => self::validatePullRequest($root),
                'plan' => self::plan($root),
                'append' => self::append($root),
                'reconcile' => self::reconcile($root),
                default => self::usage(),
            };
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Release tooling failed: '.$exception->getMessage().PHP_EOL);

            return 1;
        }
    }

    private static function validatePullRequest(string $root): int
    {
        $baseCommit = self::environment('RELEASE_BASE_SHA');
        if (preg_match('/^[0-9a-f]{40}$/D', $baseCommit) !== 1) {
            throw new ReleaseException('RELEASE_BASE_SHA must be a full lowercase 40-character commit ID.');
        }

        $repository = self::environment('GITHUB_REPOSITORY');
        $git = new GitRepository($root);
        $manifest = Manifest::fromFile($root.'/releases.json');
        $mergeBase = $git->mergeBase('HEAD', $baseCommit);
        $base = $git->manifestAt($mergeBase, 'releases.json');
        if ($base === null) {
            throw new ReleaseException('The pull-request base does not contain releases.json.');
        }
        $manifest->assertSameAs($base);
        $baseManifestJson = $git->fileAt($mergeBase, 'releases.json');
        $manifestJson = file_get_contents($root.'/releases.json');
        if (! is_string($manifestJson) || $manifestJson !== $baseManifestJson) {
            throw new ReleaseException('Human pull requests must not change releases.json; the release App owns ledger appends.');
        }

        $github = new HttpGitHubClient($repository, self::optionalEnvironment('GITHUB_TOKEN'));
        (new Validator($git, $github, 'HEAD'))->validate($manifest, $manifest);

        $intent = ReleaseIntent::fromFile($root.'/release-intent.json');
        $planner = new ReleasePlanner($git);
        $planner->assertIntentTransition($base, $git->intentAt($mergeBase, 'release-intent.json'), $intent);
        $pending = $planner->pendingEntry($manifest, $intent, 'HEAD');
        if ($pending !== null) {
            $head = $git->mergeBase('HEAD', 'HEAD');
            $candidate = $manifest->withAppended(new ReleaseEntry($pending->version, $head));
            (new Validator($git, $github, 'HEAD'))->validate($candidate, $candidate);
        }

        fwrite(STDOUT, sprintf(
            'Validated release PR inputs (%s).%s',
            $pending === null ? 'no pending release' : 'pending '.$pending->version,
            PHP_EOL,
        ));

        return 0;
    }

    private static function plan(string $root): int
    {
        $git = new GitRepository($root);
        $entry = (new ReleasePlanner($git))->pendingEntry(
            Manifest::fromFile($root.'/releases.json'),
            ReleaseIntent::fromFile($root.'/release-intent.json'),
            self::environment('RELEASE_MAIN_REF', 'origin/main'),
        );
        $pending = $entry === null ? 'false' : 'true';
        $output = "pending={$pending}\n";
        if ($entry !== null) {
            $output .= "version={$entry->version}\ntarget={$entry->commit}\n";
        }

        $outputPath = self::environment('GITHUB_OUTPUT', '');
        if ($outputPath !== '' && @file_put_contents($outputPath, $output, FILE_APPEND | LOCK_EX) === false) {
            throw new ReleaseException('GitHub output file could not be written.');
        }
        fwrite(STDOUT, $entry === null
            ? "Release intent is already recorded in the ledger.\n"
            : "Pending {$entry->version} targets {$entry->commit}.\n");

        return 0;
    }

    private static function append(string $root): int
    {
        $repository = self::environment('GITHUB_REPOSITORY');
        $mainRef = self::environment('RELEASE_MAIN_REF', 'origin/main');
        $manifest = Manifest::fromFile($root.'/releases.json');
        $git = new GitRepository($root);
        $entry = (new ReleasePlanner($git))->pendingEntry(
            $manifest,
            ReleaseIntent::fromFile($root.'/release-intent.json'),
            $mainRef,
        );

        if ($entry === null) {
            fwrite(STDOUT, "Release intent is already recorded; no ledger commit was created.\n");

            return 0;
        }

        $expectedTarget = self::environment('RELEASE_EXPECTED_TARGET', '');
        if ($expectedTarget !== '') {
            if (preg_match('/^[0-9a-f]{40}$/D', $expectedTarget) !== 1) {
                throw new ReleaseException('RELEASE_EXPECTED_TARGET must be a full lowercase 40-character commit ID.');
            }
            if ($entry->commit !== $expectedTarget) {
                throw new ReleaseException("Successful CI commit {$expectedTarget} does not match release target {$entry->commit}.");
            }
        }

        $token = self::environment('RELEASE_TOKEN');
        $candidate = $manifest->withAppended($entry);
        $github = new HttpGitHubClient($repository, $token);
        (new Validator($git, $github, $mainRef))->validate($candidate, $manifest);
        (new GitLedgerPublisher($root, $repository, $token))->append($manifest, $entry);
        fwrite(STDOUT, "Recorded {$entry->version} -> {$entry->commit} in releases.json.\n");

        return 0;
    }

    private static function validate(string $root): int
    {
        $repository = self::environment('GITHUB_REPOSITORY');
        $mainRef = self::environment('RELEASE_MAIN_REF', 'origin/main');
        $baseCommit = self::environment('RELEASE_BASE_SHA', '');
        $git = new GitRepository($root);
        $manifest = Manifest::fromFile($root.'/releases.json');
        $base = null;

        if ($baseCommit !== '') {
            if (preg_match('/^[0-9a-f]{40}$/D', $baseCommit) !== 1) {
                throw new ReleaseException('RELEASE_BASE_SHA must be a full lowercase 40-character commit ID.');
            }
            $base = $git->manifestAt($git->mergeBase('HEAD', $baseCommit), 'releases.json');
        }

        $github = new HttpGitHubClient($repository, self::optionalEnvironment('GITHUB_TOKEN'));
        $skipChecks = self::environment('RELEASE_SKIP_REQUIRED_CHECKS', '0') === '1';
        $comparison = $skipChecks ? $manifest : $base;
        $validated = (new Validator($git, $github, $mainRef))->validate($manifest, $comparison);
        $newCount = count($manifest->entriesAddedAfter($comparison));
        fwrite(STDOUT, sprintf(
            'Validated %d release entries (%d newly appended).%s',
            count($validated->manifest->entries),
            $newCount,
            PHP_EOL,
        ));

        return 0;
    }

    private static function reconcile(string $root): int
    {
        $repository = self::environment('GITHUB_REPOSITORY');
        $mainRef = self::environment('RELEASE_MAIN_REF', 'origin/main');
        $token = self::environment('RELEASE_TOKEN');
        $git = new GitRepository($root);
        $manifest = Manifest::fromFile($root.'/releases.json');
        $github = new HttpGitHubClient($repository, $token);
        $validated = (new Validator($git, $github, $mainRef))->validate($manifest, $manifest);
        $tagPublisher = new GitTagPublisher(
            $root,
            $repository,
            $token,
            self::environment('RELEASE_SIGNING_PRIVATE_KEY'),
            self::environment('RELEASE_SIGNER_NAME'),
            self::environment('RELEASE_SIGNER_EMAIL'),
        );
        $results = (new Reconciler($github, new HttpPackagistClient, tagPublisher: $tagPublisher))->reconcile($validated);
        $summary = self::summary($results);

        fwrite(STDOUT, $summary);
        $summaryPath = self::environment('GITHUB_STEP_SUMMARY', '');
        if ($summaryPath !== '' && @file_put_contents($summaryPath, $summary, FILE_APPEND | LOCK_EX) === false) {
            throw new ReleaseException('GitHub job summary could not be written.');
        }

        foreach ($results as $result) {
            if (! $result->successful) {
                return 1;
            }
        }

        return 0;
    }

    /** @param list<ReconciliationResult> $results */
    public static function summary(array $results): string
    {
        $summary = "## Release reconciliation\n\n| Version | Commit | Result | Detail |\n| --- | --- | --- | --- |\n";

        foreach ($results as $result) {
            $summary .= sprintf(
                "| %s | `%s` | %s | %s |\n",
                $result->entry->version,
                $result->entry->commit,
                self::tableCell($result->status),
                self::tableCell($result->detail),
            );
        }

        return $summary."\n";
    }

    private static function usage(): int
    {
        fwrite(STDERR, "Usage: php tools/release.php <validate|validate-pr|plan|append|reconcile>\n");

        return 2;
    }

    private static function environment(string $name, ?string $default = null): string
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            if ($default !== null) {
                return $default;
            }

            throw new ReleaseException("Required environment variable {$name} is missing.");
        }

        return $value;
    }

    private static function optionalEnvironment(string $name): ?string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? null : $value;
    }

    private static function tableCell(string $value): string
    {
        return str_replace(['\\', '|', "\r", "\n"], ['\\\\', '\\|', ' ', ' '], $value);
    }
}
