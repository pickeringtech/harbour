<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Identity;

use PickeringTech\Harbour\Contracts\WorkspaceIdentityStrategy;
use Symfony\Component\Process\Process;

final class DefaultWorkspaceIdentityStrategy implements WorkspaceIdentityStrategy
{
    public function resolve(WorkspaceContext $context): WorkspaceIdentity
    {
        $path = realpath($context->path) ?: $context->path;
        $gitCommonDirectory = $this->git($path, ['rev-parse', '--path-format=absolute', '--git-common-dir']);
        $repository = $gitCommonDirectory !== null ? (realpath($gitCommonDirectory) ?: $gitCommonDirectory) : $path;
        $remote = $this->git($path, ['config', '--get', 'remote.origin.url']);
        $branch = $this->git($path, ['symbolic-ref', '--quiet', '--short', 'HEAD']);
        $head = $this->git($path, ['rev-parse', '--verify', 'HEAD']);

        $hash = hash('sha256', implode("\0", [$repository, $remote ?? '', $path]));
        $label = $branch ?? ($head !== null ? 'detached-'.substr($head, 0, 8) : basename($path));
        $readable = $this->asciiSlug($label);
        $slug = substr($readable, 0, 48).'-'.substr($hash, 0, 8);

        return new WorkspaceIdentity('ws_'.$hash, $slug, $hash, $branch);
    }

    /** @param list<string> $arguments */
    private function git(string $path, array $arguments): ?string
    {
        $process = new Process(['git', '-C', $path, ...$arguments]);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $value = trim($process->getOutput());

        return $value !== '' ? $value : null;
    }

    private function asciiSlug(string $value): string
    {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $ascii = $transliterated === false ? '' : strtolower($transliterated);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');

        return $slug !== '' ? $slug : 'workspace';
    }
}
