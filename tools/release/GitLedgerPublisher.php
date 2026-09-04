<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Release;

use Symfony\Component\Process\Process;

final readonly class GitLedgerPublisher implements LedgerPublisher
{
    private string $remoteUrl;

    public function __construct(
        private string $directory,
        private string $repository,
        private string $token,
        ?string $remoteUrl = null,
    ) {
        if (preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $repository) !== 1) {
            throw new ReleaseException('GitHub repository must be in owner/name form.');
        }
        if ($token === '') {
            throw new ReleaseException('Release token must not be empty.');
        }

        $this->remoteUrl = $remoteUrl ?? 'https://github.com/'.$repository.'.git';
        if (! str_starts_with($this->remoteUrl, 'https://github.com/') && ! str_starts_with($this->remoteUrl, 'file://')) {
            throw new ReleaseException('Release ledger remote must use GitHub HTTPS.');
        }
    }

    public function append(Manifest $base, ReleaseEntry $entry): void
    {
        $path = $this->directory.'/releases.json';
        $current = Manifest::fromFile($path);
        $current->assertSameAs($base);
        $updated = $base->withAppended($entry);

        if (trim($this->run(['git', 'status', '--porcelain', '--untracked-files=no'], [0])->getOutput()) !== '') {
            throw new ReleaseException('Release checkout contains tracked changes before the ledger append.');
        }

        $temporary = tempnam($this->directory, '.releases-json-');
        if ($temporary === false) {
            throw new ReleaseException('Temporary release ledger file could not be created.');
        }

        try {
            $json = $updated->toJson();
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)
                || ! chmod($temporary, 0644)
                || ! rename($temporary, $path)) {
                throw new ReleaseException('Release ledger could not be written atomically.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        $this->run(['git', 'add', '--', 'releases.json'], [0]);
        $staged = trim($this->run(['git', 'diff', '--cached', '--name-only'], [0])->getOutput());
        if ($staged !== 'releases.json') {
            throw new ReleaseException('Automated release commit must contain only releases.json.');
        }

        $this->run([
            'git',
            '-c', 'user.name=harbour-release[bot]',
            '-c', 'user.email=harbour-release[bot]@users.noreply.github.com',
            'commit', '--no-gpg-sign', '--message', 'chore: record Harbour '.$entry->version.' release target [skip ci]',
        ], [0]);

        $environment = [];
        if (str_starts_with($this->remoteUrl, 'https://github.com/')) {
            $environment = [
                'GIT_CONFIG_COUNT' => '1',
                'GIT_CONFIG_KEY_0' => 'http.https://github.com/.extraheader',
                'GIT_CONFIG_VALUE_0' => 'AUTHORIZATION: basic '.base64_encode('x-access-token:'.$this->token),
            ];
        }
        $push = $this->run(
            ['git', 'push', '--porcelain', $this->remoteUrl, 'HEAD:refs/heads/main'],
            [0, 1],
            $environment,
        );

        if ($push->getExitCode() !== 0) {
            throw new ReleaseException('Release ledger push conflicted with a concurrent main update; rerun reconciliation.');
        }
    }

    /**
     * @param  list<string>  $command
     * @param  list<int>  $allowedExitCodes
     * @param  array<string, string>  $environment
     */
    private function run(array $command, array $allowedExitCodes, array $environment = []): Process
    {
        $process = new Process($command, $this->directory, $environment);
        $process->setTimeout(30);
        $process->run();

        if (! in_array($process->getExitCode(), $allowedExitCodes, true)) {
            $message = trim($process->getErrorOutput());
            throw new ReleaseException('Git ledger command failed'.($message === '' ? '.' : ": {$message}"));
        }

        return $process;
    }
}
