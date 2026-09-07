<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Integrations\Worktrunk\WorktrunkIntegration;
use PickeringTech\Harbour\Process\ProcessResult;
use PickeringTech\Harbour\Process\SymfonyCommandRunner;

final class WorktrunkLifecycleIntegrationTest extends TestCase
{
    private string $root;

    private string $repository;

    private string $binary;

    private SymfonyCommandRunner $runner;

    protected function setUp(): void
    {
        if (getenv('HARBOUR_WORKTRUNK_INTEGRATION') !== '1') {
            self::markTestSkipped('Set HARBOUR_WORKTRUNK_INTEGRATION=1 to exercise the pinned real Worktrunk release.');
        }

        $configuredBinary = getenv('HARBOUR_WORKTRUNK_BINARY');
        $this->binary = is_string($configuredBinary) && $configuredBinary !== '' ? $configuredBinary : 'wt';
        $this->root = sys_get_temp_dir()."/harbour-wt-ü ' ; dollar$(nope)-paths-".bin2hex(random_bytes(4));
        $this->repository = $this->root.'/project with spaces';
        mkdir($this->repository, 0700, true);
        $this->runner = new SymfonyCommandRunner;

        $this->mustRun(['git', 'init', '--quiet', '--initial-branch=main'], $this->repository);
        $this->mustRun(['git', 'config', 'user.name', 'Harbour Test'], $this->repository);
        $this->mustRun(['git', 'config', 'user.email', 'harbour@example.test'], $this->repository);
        file_put_contents($this->repository.'/.gitignore', "/vendor/\n/.harbour.json\n/.lifecycle.log\n/.fail-teardown\n");
        file_put_contents($this->repository.'/composer.json', <<<'JSON'
        {
            "name": "harbour/worktrunk-fixture",
            "scripts": {
                "workspace:setup": ["@php lifecycle.php setup"],
                "workspace:teardown": ["@php lifecycle.php teardown"]
            }
        }
        JSON);
        file_put_contents($this->repository.'/lifecycle.php', <<<'PHP'
        <?php
        $action = $argv[1] ?? '';
        if ($action === 'setup') {
            if (!is_file('.harbour.json')) {
                file_put_contents('.harbour.json', "owned\n");
            }
            file_put_contents('.lifecycle.log', "setup\n", FILE_APPEND);
            exit(0);
        }
        if ($action === 'teardown') {
            if (is_file('.fail-teardown')) {
                fwrite(STDERR, "injected teardown failure\n");
                exit(23);
            }
            if (is_file('.harbour.json')) {
                unlink('.harbour.json');
            }
            file_put_contents('.lifecycle.log', "teardown\n", FILE_APPEND);
            exit(0);
        }
        exit(2);
        PHP);
        $this->mustRun(['composer', 'install', '--no-interaction', '--no-progress'], $this->repository);
        $this->mustRun(['git', 'add', '.gitignore', 'composer.json', 'composer.lock', 'lifecycle.php'], $this->repository);
        $this->mustRun(['git', 'commit', '--quiet', '-m', 'fixture'], $this->repository);

        $integration = new WorktrunkIntegration($this->repository, $this->runner, binary: $this->binary);
        $integration->install($integration->prepare());
        self::assertSame('equivalent', $integration->status()['configuration']);
        $this->mustRun(['git', 'add', WorktrunkIntegration::CONFIGURATION_PATH], $this->repository);
        $this->mustRun(['git', 'commit', '--quiet', '-m', 'configure Worktrunk'], $this->repository);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            $this->removeDirectory($this->root);
        }
    }

    public function test_real_worktrunk_creation_and_blocking_removal_lifecycle(): void
    {
        $this->mustRun([$this->binary, '-C', $this->repository, '--yes', 'switch', '--create', 'workspace-a'], $this->repository);
        $this->mustRun([$this->binary, '-C', $this->repository, '--yes', 'switch', '--create', 'workspace-b'], $this->repository);
        $a = $this->worktree('workspace-a');
        $b = $this->worktree('workspace-b');

        self::assertFileExists($a.'/.harbour.json');
        self::assertFileExists($b.'/.harbour.json');
        self::assertSame("setup\n", file_get_contents($a.'/.lifecycle.log'));
        self::assertSame("setup\n", file_get_contents($b.'/.lifecycle.log'));

        $this->mustRun([$this->binary, '-C', $a, '--yes', 'hook', 'pre-start'], $a);
        self::assertFileExists($a.'/.harbour.json');
        self::assertSame("setup\nsetup\n", file_get_contents($a.'/.lifecycle.log'));

        file_put_contents($a.'/.fail-teardown', "fail\n");
        $failed = $this->runner->run([
            $this->binary, '-C', $this->repository, '--yes', 'remove', '--foreground', '--force', 'workspace-a',
        ], $this->repository);
        self::assertSame(23, $failed->exitCode);
        self::assertDirectoryExists($a);
        self::assertFileExists($a.'/.harbour.json');

        unlink($a.'/.fail-teardown');
        $this->mustRun([
            $this->binary, '-C', $this->repository, '--yes', 'remove', '--foreground', '--force', 'workspace-a',
        ], $this->repository);
        self::assertDirectoryDoesNotExist($a);
        self::assertDirectoryExists($b);
        self::assertFileExists($b.'/.harbour.json');

        $this->mustRun([
            $this->binary, '-C', $this->repository, '--yes', 'remove', '--foreground', '--force', 'workspace-b',
        ], $this->repository);
        self::assertDirectoryDoesNotExist($b);
    }

    public function test_real_worktrunk_merge_runs_pre_remove_before_checkout_deletion(): void
    {
        $this->mustRun([$this->binary, '-C', $this->repository, '--yes', 'switch', '--create', 'merge-lifecycle'], $this->repository);
        $worktree = $this->worktree('merge-lifecycle');
        file_put_contents($worktree.'/merged.txt', "merged\n");
        $this->mustRun(['git', 'add', 'merged.txt'], $worktree);
        $this->mustRun(['git', 'commit', '--quiet', '-m', 'merge fixture'], $worktree);

        $merge = $this->mustRun([$this->binary, '-C', $worktree, '--yes', 'merge', '--no-squash'], $worktree);

        self::assertStringContainsString('Running pre-remove project:harbour-teardown', $merge->output."\n".$merge->errorOutput);
        self::assertFileDoesNotExist($worktree.'/.harbour.json');
        self::assertFileExists($this->repository.'/merged.txt');
    }

    private function worktree(string $branch): string
    {
        $result = $this->mustRun(['git', 'worktree', 'list', '--porcelain', '-z'], $this->repository);
        $path = null;
        $candidate = null;
        foreach (explode("\0", $result->output) as $field) {
            if (str_starts_with($field, 'worktree ')) {
                $candidate = substr($field, strlen('worktree '));
            } elseif ($field === "branch refs/heads/{$branch}") {
                $path = $candidate;
            }
        }
        self::assertIsString($path);

        return $path;
    }

    /** @param list<string> $command */
    private function mustRun(array $command, string $directory): ProcessResult
    {
        $result = $this->runner->run($command, $directory);
        self::assertSame(0, $result->exitCode, $result->errorOutput."\n".$result->output);

        return $result;
    }

    private function removeDirectory(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeDirectory($path.'/'.$entry);
        }
        @rmdir($path);
    }
}
