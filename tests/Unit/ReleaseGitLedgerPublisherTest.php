<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Release\GitLedgerPublisher;
use PickeringTech\Harbour\Release\Manifest;
use PickeringTech\Harbour\Release\ReleaseEntry;
use PickeringTech\Harbour\Release\ReleaseException;
use Symfony\Component\Process\Process;

final class ReleaseGitLedgerPublisherTest extends TestCase
{
    private string $root;

    private string $work;

    private string $remote;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/harbour-release-ledger-'.bin2hex(random_bytes(6));
        $this->work = $this->root.'/work';
        $this->remote = $this->root.'/remote.git';
        mkdir($this->work, 0700, true);
        $this->process(['git', 'init', '--initial-branch=main'], $this->work);
        $this->process(['git', 'config', 'user.name', 'Test Author'], $this->work);
        $this->process(['git', 'config', 'user.email', 'test@example.test'], $this->work);
        file_put_contents($this->work.'/releases.json', $this->manifest()->toJson());
        $this->process(['git', 'add', 'releases.json'], $this->work);
        $this->process(['git', 'commit', '-m', 'seed'], $this->work);
        $this->process(['git', 'init', '--bare', $this->remote]);
        $this->process(['git', 'push', 'file://'.$this->remote, 'main:main'], $this->work);
        $this->process(['git', '--git-dir='.$this->remote, 'symbolic-ref', 'HEAD', 'refs/heads/main']);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function test_it_pushes_one_file_in_a_non_force_control_plane_commit(): void
    {
        $base = $this->manifest();
        $entry = new ReleaseEntry('v1.1.0', str_repeat('b', 40));

        (new GitLedgerPublisher($this->work, 'pickeringtech/harbour', 'token', 'file://'.$this->remote))
            ->append($base, $entry);

        $remoteJson = $this->process([
            'git', '--git-dir='.$this->remote, 'show', 'main:releases.json',
        ]);
        self::assertSame($entry->commit, Manifest::fromJson($remoteJson)->latest()?->commit);
        self::assertSame(
            ['releases.json'],
            array_values(array_filter(explode("\n", trim($this->process([
                'git', '--git-dir='.$this->remote, 'diff-tree', '--no-commit-id', '--name-only', '-r', 'main',
            ]))))),
        );
        self::assertStringContainsString('[skip ci]', $this->process([
            'git', '--git-dir='.$this->remote, 'log', '-1', '--format=%s', 'main',
        ]));
    }

    public function test_it_fails_closed_when_main_advanced_concurrently(): void
    {
        $other = $this->root.'/other';
        $this->process(['git', 'clone', 'file://'.$this->remote, $other]);
        $this->process(['git', 'config', 'user.name', 'Other'], $other);
        $this->process(['git', 'config', 'user.email', 'other@example.test'], $other);
        file_put_contents($other.'/README.md', "concurrent\n");
        $this->process(['git', 'add', 'README.md'], $other);
        $this->process(['git', 'commit', '-m', 'concurrent'], $other);
        $this->process(['git', 'push', 'origin', 'main'], $other);

        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessage('conflicted with a concurrent main update');

        (new GitLedgerPublisher($this->work, 'pickeringtech/harbour', 'token', 'file://'.$this->remote))
            ->append($this->manifest(), new ReleaseEntry('v1.1.0', str_repeat('b', 40)));
    }

    private function manifest(): Manifest
    {
        return Manifest::fromJson(json_encode(['schema' => 1, 'releases' => [[
            'version' => 'v1.0.0',
            'commit' => str_repeat('a', 40),
        ]]], JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $command */
    private function process(array $command, ?string $directory = null): string
    {
        $process = new Process($command, $directory);
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
