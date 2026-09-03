<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Identity\DefaultWorkspaceIdentityStrategy;
use PickeringTech\Harbour\Identity\WorkspaceContext;
use Symfony\Component\Process\Process;

final class GitIdentityTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/harbour-git-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->git(['init', '--initial-branch=main'], $this->directory);
        $this->git(['config', 'user.email', 'tests@example.test'], $this->directory);
        $this->git(['config', 'user.name', 'Harbour Tests'], $this->directory);
        file_put_contents($this->directory.'/README.md', "test\n");
        $this->git(['add', 'README.md'], $this->directory);
        $this->git(['commit', '-m', 'Initial'], $this->directory);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    #[DataProvider('branchNames')]
    public function test_real_git_branch_names_are_descriptive_but_safe(string $branch): void
    {
        $this->git(['checkout', '-b', $branch], $this->directory);
        $identity = (new DefaultWorkspaceIdentityStrategy)->resolve(new WorkspaceContext($this->directory, 'project'));

        self::assertSame($branch, $identity->branch());
        self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', $identity->slug());
        self::assertLessThanOrEqual(57, strlen($identity->slug()));
    }

    public function test_primary_worktree_secondary_worktree_and_detached_head_are_distinct(): void
    {
        $worktree = $this->directory.'-feature';
        $this->git(['worktree', 'add', '-b', 'feature/payment', $worktree], $this->directory);
        $strategy = new DefaultWorkspaceIdentityStrategy;
        $primary = $strategy->resolve(new WorkspaceContext($this->directory, 'project'));
        $secondary = $strategy->resolve(new WorkspaceContext($worktree, 'project'));

        self::assertNotSame($primary->id(), $secondary->id());
        self::assertSame('feature/payment', $secondary->branch());

        $this->git(['checkout', '--detach'], $worktree);
        $detached = $strategy->resolve(new WorkspaceContext($worktree, 'project'));
        self::assertNull($detached->branch());
        self::assertStringStartsWith('detached-', $detached->slug());

        $this->git(['worktree', 'remove', '--force', $worktree], $this->directory);
    }

    public function test_non_git_directory_degrades_to_path_identity(): void
    {
        $outside = $this->directory.'-plain';
        mkdir($outside, 0700);
        $identity = (new DefaultWorkspaceIdentityStrategy)->resolve(new WorkspaceContext($outside, 'plain'));

        self::assertNull($identity->branch());
        self::assertStringStartsWith('harbour-git-', $identity->slug());
        rmdir($outside);
    }

    /** @return iterable<string, array{string}> */
    public static function branchNames(): iterable
    {
        yield 'slash' => ['feature/payment-retry'];
        yield 'unicode' => ['feature/修正-payment'];
        yield 'long' => ['feature/'.str_repeat('very-long-', 20).'end'];
    }

    /** @param list<string> $arguments */
    private function git(array $arguments, string $directory): void
    {
        $process = new Process(['git', '-C', $directory, ...$arguments]);
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.'/'.$entry;
            is_dir($child) && ! is_link($child) ? $this->removeDirectory($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
