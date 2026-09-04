<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Release;

use Symfony\Component\Process\Process;

final readonly class GitTagPublisher implements TagPublisher
{
    private string $privateKey;

    private string $remoteUrl;

    public function __construct(
        private string $directory,
        private string $repository,
        private string $token,
        string $privateKey,
        private string $signerName,
        private string $signerEmail,
        ?string $remoteUrl = null,
    ) {
        if (preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $repository) !== 1) {
            throw new ReleaseException('GitHub repository must be in owner/name form.');
        }
        if ($token === '') {
            throw new ReleaseException('Release token must not be empty.');
        }
        $privateKey = rtrim(str_replace(["\r\n", "\r"], "\n", $privateKey), "\n")."\n";
        if (! str_starts_with($privateKey, '-----BEGIN OPENSSH PRIVATE KEY-----')) {
            throw new ReleaseException('Release signing key must be an OpenSSH private key.');
        }
        foreach (['name' => $signerName, 'email' => $signerEmail] as $field => $value) {
            if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new ReleaseException("Release signer {$field} is invalid.");
            }
        }
        if (filter_var($signerEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new ReleaseException('Release signer email is invalid.');
        }

        $this->privateKey = $privateKey;
        $this->remoteUrl = $remoteUrl ?? 'https://github.com/'.$repository.'.git';
        if (! str_starts_with($this->remoteUrl, 'https://github.com/') && ! str_starts_with($this->remoteUrl, 'file://')) {
            throw new ReleaseException('Release tag remote must use GitHub HTTPS.');
        }
    }

    public function createTagObject(ReleaseEntry $entry): ReleaseTag
    {
        $this->assertEntry($entry);
        $reference = 'refs/tags/'.$entry->version;
        $existing = $this->run(['git', 'show-ref', '--verify', '--quiet', $reference], [0, 1]);

        if ($existing->getExitCode() !== 0) {
            $keyPath = $this->temporarySigningKey();
            try {
                $this->run([
                    'git',
                    '-c', 'gpg.format=ssh',
                    '-c', 'user.signingkey='.$keyPath,
                    '-c', 'user.name='.$this->signerName,
                    '-c', 'user.email='.$this->signerEmail,
                    'tag', '--sign', '--message', 'Harbour '.$entry->version, $entry->version, $entry->commit,
                ], [0]);
            } finally {
                $this->destroySigningKey($keyPath);
            }
        }

        $commit = trim($this->run(['git', 'rev-parse', $reference.'^{}'], [0])->getOutput());
        if ($commit !== $entry->commit) {
            throw new ReleaseException("Local tag {$entry->version} points to {$commit}, expected {$entry->commit}; it will not be moved.");
        }
        $objectSha = trim($this->run(['git', 'rev-parse', $reference.'^{tag}'], [0])->getOutput());
        if (preg_match('/^[0-9a-f]{40}$/D', $objectSha) !== 1) {
            throw new ReleaseException("Git returned an invalid tag object ID for {$entry->version}.");
        }
        $object = $this->run(['git', 'cat-file', '-p', $objectSha], [0])->getOutput();
        if (! str_contains($object, '-----BEGIN SSH SIGNATURE-----')) {
            throw new ReleaseException("Local tag {$entry->version} does not contain an SSH signature.");
        }

        return new ReleaseTag($entry->version, $entry->commit, $objectSha, true, true, 'signed-locally');
    }

    public function createTagReference(ReleaseTag $tag): bool
    {
        if (preg_match('/^v(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D', $tag->version) !== 1) {
            throw new ReleaseException('Release tag name is invalid.');
        }

        $reference = 'refs/tags/'.$tag->version;
        $environment = [];
        if (str_starts_with($this->remoteUrl, 'https://github.com/')) {
            $environment = [
                'GIT_CONFIG_COUNT' => '1',
                'GIT_CONFIG_KEY_0' => 'http.https://github.com/.extraheader',
                'GIT_CONFIG_VALUE_0' => 'AUTHORIZATION: basic '.base64_encode('x-access-token:'.$this->token),
            ];
        }
        $process = $this->run(
            ['git', 'push', '--porcelain', $this->remoteUrl, $reference.':'.$reference],
            [0, 1],
            $environment,
        );
        if ($process->getExitCode() !== 0) {
            return false;
        }

        return preg_match('/^\*\s+refs\/tags\//m', $process->getOutput()) === 1;
    }

    private function assertEntry(ReleaseEntry $entry): void
    {
        if (preg_match('/^v(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D', $entry->version) !== 1) {
            throw new ReleaseException('Release tag name is invalid.');
        }
        if (preg_match('/^[0-9a-f]{40}$/D', $entry->commit) !== 1) {
            throw new ReleaseException('Release commit ID is invalid.');
        }
    }

    private function temporarySigningKey(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'harbour-release-signing-');
        if ($path === false) {
            throw new ReleaseException('Temporary release signing key could not be secured.');
        }
        if (! chmod($path, 0600) || file_put_contents($path, $this->privateKey, LOCK_EX) !== strlen($this->privateKey)) {
            $this->destroySigningKey($path);
            throw new ReleaseException('Temporary release signing key could not be secured.');
        }

        return $path;
    }

    private function destroySigningKey(string $path): void
    {
        $size = @filesize($path);
        if (is_int($size) && $size > 0) {
            @file_put_contents($path, str_repeat("\0", $size), LOCK_EX);
        }
        @unlink($path);
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
            throw new ReleaseException('Git tag command failed'.($message === '' ? '.' : ": {$message}"));
        }

        return $process;
    }
}
