<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Release\GitRepository;
use PickeringTech\Harbour\Release\ReleaseException;
use Symfony\Component\Process\Process;

final class ReleaseGitRepositoryTest extends TestCase
{
    private string $directory;

    private string $mainCommit;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/harbour-release-git-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->git(['init', '--initial-branch=main']);
        $this->git(['config', 'user.name', 'Harbour Test']);
        $this->git(['config', 'user.email', 'harbour@example.test']);
        file_put_contents($this->directory.'/CHANGELOG.md', "## [1.0.0] - 2026-09-04\n\n- Notes.\n");
        file_put_contents($this->directory.'/releases.json', '{"schema":1,"releases":[]}');
        $this->git(['add', 'CHANGELOG.md', 'releases.json']);
        $this->git(['commit', '-m', 'main']);
        $this->mainCommit = trim($this->git(['rev-parse', 'HEAD']));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function test_it_checks_commit_type_ancestry_and_files_without_a_shell(): void
    {
        $repository = new GitRepository($this->directory);

        $repository->assertCommit($this->mainCommit);
        $repository->assertReachableFrom($this->mainCommit, 'main');

        self::assertStringContainsString('- Notes.', $repository->fileAt($this->mainCommit, 'CHANGELOG.md'));
        self::assertSame($this->mainCommit, $repository->mergeBase('HEAD', $this->mainCommit));
        $manifest = $repository->manifestAt($this->mainCommit, 'releases.json');
        self::assertNotNull($manifest);
        self::assertCount(0, $manifest->entries);
        self::assertNull($repository->manifestAt($this->mainCommit, 'missing.json'));
    }

    public function test_it_rejects_missing_non_commit_and_unreachable_objects(): void
    {
        $repository = new GitRepository($this->directory);
        $blob = trim($this->git(['hash-object', '-w', '--stdin'], 'blob'));

        foreach ([str_repeat('f', 40), $blob] as $object) {
            try {
                $repository->assertCommit($object);
                self::fail('A missing or non-commit object was accepted.');
            } catch (ReleaseException $exception) {
                self::assertMatchesRegularExpression('/does not exist|not a commit/', $exception->getMessage());
            }
        }

        $this->git(['switch', '--orphan', 'side']);
        file_put_contents($this->directory.'/side.txt', 'side');
        $this->git(['add', 'side.txt']);
        $this->git(['commit', '-m', 'side']);
        $sideCommit = trim($this->git(['rev-parse', 'HEAD']));

        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessage('not reachable');
        $repository->assertReachableFrom($sideCommit, 'main');
    }

    /** @param list<string> $arguments */
    private function git(array $arguments, ?string $input = null): string
    {
        $process = new Process(['git', ...$arguments], $this->directory, input: $input);
        $process->mustRun();

        return $process->getOutput();
    }

    private function removeDirectory(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.'/'.$entry;
            is_dir($child) ? $this->removeDirectory($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
