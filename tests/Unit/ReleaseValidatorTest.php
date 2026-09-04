<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Release\Manifest;
use PickeringTech\Harbour\Release\ReleaseException;
use PickeringTech\Harbour\Release\ReleasePolicy;
use PickeringTech\Harbour\Release\Validator;
use PickeringTech\Harbour\Tests\Support\FakeGitHubClient;
use PickeringTech\Harbour\Tests\Support\FakeReleaseRepository;

final class ReleaseValidatorTest extends TestCase
{
    public function test_it_validates_the_exact_historical_seed_without_rechecking_old_ci(): void
    {
        [$manifest, $repository] = $this->fixture();
        $github = new FakeGitHubClient;

        $validated = (new Validator($repository, $github, 'origin/main'))->validate($manifest);

        self::assertCount(3, $validated->manifest->entries);
        self::assertStringContainsString('Initial release.', $validated->notes['v0.0.1']);
        self::assertSame([], $github->checks);
    }

    public function test_new_entries_require_every_named_release_check(): void
    {
        [$base, $repository, $releases] = $this->fixture();
        $commit = str_repeat('d', 40);
        $releases[] = ['version' => 'v0.0.4', 'commit' => $commit];
        $manifest = $this->manifest($releases);
        $repository->types[$commit] = 'commit';
        $repository->reachable[$commit] = true;
        $repository->files[$commit]['CHANGELOG.md'] = "## [0.0.4] - 2026-09-05\n\n- Automated release.\n";
        $github = new FakeGitHubClient;
        $github->checks[$commit] = array_fill_keys(ReleasePolicy::REQUIRED_CHECKS, 'success');

        $validated = (new Validator($repository, $github, 'origin/main'))->validate($manifest, $base);

        self::assertSame('- Automated release.', $validated->notes['v0.0.4']);
    }

    public function test_a_missing_or_failed_required_check_rejects_the_declaration(): void
    {
        [$base, $repository, $releases] = $this->fixture();
        $commit = str_repeat('d', 40);
        $releases[] = ['version' => 'v0.0.4', 'commit' => $commit];
        $manifest = $this->manifest($releases);
        $repository->types[$commit] = 'commit';
        $repository->reachable[$commit] = true;
        $repository->files[$commit]['CHANGELOG.md'] = "## [0.0.4] - 2026-09-05\n\n- Automated release.\n";
        $github = new FakeGitHubClient;
        $github->checks[$commit] = array_fill_keys(ReleasePolicy::REQUIRED_CHECKS, 'success');
        unset($github->checks[$commit]['Coverage']);
        $github->checks[$commit]['Mutation testing'] = 'failure';

        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessage('Coverage (missing), Mutation testing (failure)');

        (new Validator($repository, $github, 'origin/main'))->validate($manifest, $base);
    }

    #[DataProvider('targetFailureProvider')]
    public function test_target_object_reachability_and_changelog_are_enforced(string $failure, string $message): void
    {
        [$base, $repository, $releases] = $this->fixture();
        $commit = str_repeat('d', 40);
        $releases[] = ['version' => 'v0.0.4', 'commit' => $commit];
        $manifest = $this->manifest($releases);
        $repository->types[$commit] = $failure === 'missing' ? '' : ($failure === 'type' ? 'blob' : 'commit');
        if ($failure === 'missing') {
            unset($repository->types[$commit]);
        }
        $repository->reachable[$commit] = $failure !== 'ancestry';
        if ($failure !== 'changelog') {
            $repository->files[$commit]['CHANGELOG.md'] = "## [0.0.4] - 2026-09-05\n\n- Notes.\n";
        }

        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessage($message);

        (new Validator($repository, new FakeGitHubClient, 'origin/main'))->validate($manifest, $base);
    }

    /** @return iterable<string, array{string, string}> */
    public static function targetFailureProvider(): iterable
    {
        yield 'missing object' => ['missing', 'does not exist'];
        yield 'wrong object type' => ['type', 'is not a commit'];
        yield 'not on main' => ['ancestry', 'not reachable'];
        yield 'missing changelog' => ['changelog', 'CHANGELOG.md is absent'];
    }

    public function test_the_historical_seed_cannot_be_changed_even_without_a_base_manifest(): void
    {
        [, $repository, $releases] = $this->fixture();
        $changed = [];
        foreach ($releases as $index => $release) {
            $changed[] = [
                'version' => $release['version'],
                'commit' => $index === 0 ? str_repeat('e', 40) : $release['commit'],
            ];
        }

        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessage('exact v0.0.1-v0.0.3 historical seed');

        (new Validator($repository, new FakeGitHubClient, 'origin/main'))->validate($this->manifest($changed));
    }

    /**
     * @return array{Manifest, FakeReleaseRepository, list<array{version: string, commit: string}>}
     */
    private function fixture(): array
    {
        $releases = [];
        $repository = new FakeReleaseRepository;
        $notes = [
            'v0.0.1' => 'Initial release.',
            'v0.0.2' => 'Second release.',
            'v0.0.3' => 'Third release.',
        ];

        foreach (ReleasePolicy::LEGACY_RELEASES as $version => $commit) {
            $releases[] = ['version' => $version, 'commit' => $commit];
            $repository->types[$commit] = 'commit';
            $repository->reachable[$commit] = true;
            $repository->files[$commit]['CHANGELOG.md'] = sprintf(
                "## [%s] - 2026-09-04\n\n- %s\n",
                substr($version, 1),
                $notes[$version],
            );
        }

        $manifest = $this->manifest($releases);

        return [$manifest, $repository, $releases];
    }

    /** @param list<array{version: string, commit: string}> $releases */
    private function manifest(array $releases): Manifest
    {
        return Manifest::fromJson(json_encode(['schema' => 1, 'releases' => $releases], JSON_THROW_ON_ERROR));
    }
}
