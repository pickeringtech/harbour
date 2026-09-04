<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Release;

use Symfony\Component\Process\Process;

final readonly class GitRepository implements ReleaseRepository
{
    public function __construct(private string $directory) {}

    public function assertCommit(string $commit): void
    {
        $process = $this->run(['git', 'cat-file', '-t', $commit], [0, 128]);

        if ($process->getExitCode() !== 0) {
            throw new ReleaseException("Declared object {$commit} does not exist in the checkout.");
        }
        if (trim($process->getOutput()) !== 'commit') {
            throw new ReleaseException("Declared object {$commit} is not a commit.");
        }
    }

    public function assertReachableFrom(string $commit, string $mainRef): void
    {
        $process = $this->run(['git', 'merge-base', '--is-ancestor', $commit, $mainRef], [0, 1]);

        if ($process->getExitCode() !== 0) {
            throw new ReleaseException("Declared commit {$commit} is not reachable from {$mainRef}.");
        }
    }

    public function fileAt(string $commit, string $path): string
    {
        if (preg_match('/^[A-Za-z0-9._\/-]+$/D', $path) !== 1 || str_contains($path, '..')) {
            throw new ReleaseException("Repository path [{$path}] is invalid.");
        }

        $process = $this->run(['git', 'show', "{$commit}:{$path}"], [0, 128]);

        if ($process->getExitCode() !== 0) {
            throw new ReleaseException("Repository file {$path} is absent at {$commit}.");
        }

        return $process->getOutput();
    }

    public function manifestAt(string $commit, string $path): ?Manifest
    {
        $process = $this->run(['git', 'show', "{$commit}:{$path}"], [0, 128]);

        return $process->getExitCode() === 0 ? Manifest::fromJson($process->getOutput()) : null;
    }

    public function mergeBase(string $left, string $right): string
    {
        foreach ([$left, $right] as $revision) {
            if (preg_match('/^(?:HEAD|[0-9a-f]{40})$/D', $revision) !== 1) {
                throw new ReleaseException("Merge-base revision [{$revision}] is invalid.");
            }
        }

        $process = $this->run(['git', 'merge-base', $left, $right], [0]);
        $commit = trim($process->getOutput());
        if (preg_match('/^[0-9a-f]{40}$/D', $commit) !== 1) {
            throw new ReleaseException('Git returned an invalid merge-base commit.');
        }

        return $commit;
    }

    /** @param list<string> $command
     * @param  list<int>  $allowedExitCodes
     */
    private function run(array $command, array $allowedExitCodes): Process
    {
        $process = new Process($command, $this->directory);
        $process->setTimeout(30);
        $process->run();

        if (! in_array($process->getExitCode(), $allowedExitCodes, true)) {
            $message = trim($process->getErrorOutput());
            throw new ReleaseException('Git command failed'.($message === '' ? '.' : ": {$message}"));
        }

        return $process;
    }
}
