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
                'reconcile' => self::reconcile($root),
                default => self::usage(),
            };
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Release tooling failed: '.$exception->getMessage().PHP_EOL);

            return 1;
        }
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
        fwrite(STDERR, "Usage: php tools/release.php <validate|reconcile>\n");

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
