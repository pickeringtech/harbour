<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Release;

use JsonException;

final readonly class HttpGitHubClient implements GitHubClient
{
    private const API_VERSION = '2026-03-10';

    public function __construct(
        private string $repository,
        private ?string $token,
        private string $apiUrl = 'https://api.github.com',
    ) {
        if (preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $repository) !== 1) {
            throw new ReleaseException('GitHub repository must be in owner/name form.');
        }
        if (! str_starts_with($apiUrl, 'https://')) {
            throw new ReleaseException('GitHub API URL must use HTTPS.');
        }
    }

    public function tag(string $version): ?ReleaseTag
    {
        $reference = $this->request('GET', '/repos/'.$this->repository.'/git/ref/tags/'.rawurlencode($version), allowed: [200, 404]);
        if ($reference->status === 404) {
            return null;
        }

        $object = $this->array($reference->data, 'object');
        $type = $this->string($object, 'type');
        $sha = $this->string($object, 'sha');

        if ($type !== 'tag') {
            return new ReleaseTag($version, $sha, $sha, false, false, 'lightweight');
        }

        $tag = $this->request('GET', '/repos/'.$this->repository.'/git/tags/'.$sha);
        $target = $this->array($tag->data, 'object');
        $verification = $this->array($tag->data, 'verification');
        $targetType = $this->string($target, 'type');

        return new ReleaseTag(
            $this->string($tag->data, 'tag'),
            $targetType === 'commit' ? $this->string($target, 'sha') : '',
            $this->string($tag->data, 'sha'),
            true,
            ($verification['verified'] ?? false) === true,
            is_string($verification['reason'] ?? null) ? $verification['reason'] : 'unknown',
        );
    }

    public function release(string $version): ?GitHubRelease
    {
        for ($page = 1; $page <= 100; $page++) {
            $response = $this->request('GET', '/repos/'.$this->repository.'/releases?per_page=100&page='.$page);
            foreach ($response->data as $release) {
                if (is_array($release) && ($release['tag_name'] ?? null) === $version) {
                    return $this->releaseFrom($release);
                }
            }
            if (count($response->data) < 100) {
                return null;
            }
        }

        throw new ReleaseException('GitHub release pagination exceeded 10,000 entries.');
    }

    public function checks(string $commit): array
    {
        $checks = [];

        for ($page = 1; $page <= 100; $page++) {
            $response = $this->request('GET', '/repos/'.$this->repository.'/commits/'.$commit.'/check-runs?per_page=100&page='.$page);
            $runs = $this->array($response->data, 'check_runs');

            foreach ($runs as $run) {
                if (! is_array($run)) {
                    continue;
                }
                $app = $run['app'] ?? null;
                $name = $run['name'] ?? null;
                $conclusion = $run['conclusion'] ?? null;
                $status = $run['status'] ?? null;
                if (is_array($app) && ($app['slug'] ?? null) === 'github-actions' && is_string($name) && ! isset($checks[$name])) {
                    $checks[$name] = is_string($conclusion) ? $conclusion : (is_string($status) ? $status : 'unknown');
                }
            }
            if (count($runs) < 100) {
                break;
            }
        }

        return $checks;
    }

    public function immutableReleasesEnabled(): bool
    {
        $response = $this->request('GET', '/repos/'.$this->repository.'/immutable-releases', allowed: [200, 404]);

        return $response->status === 200 && ($response->data['enabled'] ?? false) === true;
    }

    public function createTagObject(ReleaseEntry $entry): ReleaseTag
    {
        $response = $this->request('POST', '/repos/'.$this->repository.'/git/tags', [
            'tag' => $entry->version,
            'message' => 'Harbour '.$entry->version,
            'object' => $entry->commit,
            'type' => 'commit',
        ], [201]);
        $object = $this->array($response->data, 'object');
        $verification = $this->array($response->data, 'verification');

        return new ReleaseTag(
            $this->string($response->data, 'tag'),
            $this->string($object, 'sha'),
            $this->string($response->data, 'sha'),
            true,
            ($verification['verified'] ?? false) === true,
            is_string($verification['reason'] ?? null) ? $verification['reason'] : 'unknown',
        );
    }

    public function createTagReference(ReleaseTag $tag): bool
    {
        $response = $this->request('POST', '/repos/'.$this->repository.'/git/refs', [
            'ref' => 'refs/tags/'.$tag->version,
            'sha' => $tag->objectSha,
        ], [201, 422]);

        return $response->status === 201;
    }

    public function createDraftRelease(ReleaseEntry $entry, string $notes): GitHubRelease
    {
        $response = $this->request('POST', '/repos/'.$this->repository.'/releases', [
            'tag_name' => $entry->version,
            'target_commitish' => $entry->commit,
            'name' => $entry->version,
            'body' => $notes,
            'draft' => true,
            'prerelease' => false,
            'generate_release_notes' => false,
        ], [201, 422]);

        if ($response->status === 422) {
            throw new GitHubConflict("Release {$entry->version} appeared concurrently.");
        }

        return $this->releaseFrom($response->data);
    }

    public function publishRelease(GitHubRelease $release): GitHubRelease
    {
        if (! $release->draft) {
            throw new ReleaseException("Release {$release->tagName} is already published and cannot be updated.");
        }

        $response = $this->request('PATCH', '/repos/'.$this->repository.'/releases/'.$release->id, [
            'draft' => false,
        ]);

        return $this->releaseFrom($response->data);
    }

    /** @param array<string, mixed>|null $body
     * @param  list<int>  $allowed
     */
    private function request(string $method, string $path, ?array $body = null, array $allowed = [200]): HttpResponse
    {
        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: harbour-release-reconciler',
            'X-GitHub-Api-Version: '.self::API_VERSION,
        ];
        if ($this->token !== null && $this->token !== '') {
            $headers[] = 'Authorization: Bearer '.$this->token;
        }

        $content = null;
        if ($body !== null) {
            try {
                $content = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $exception) {
                throw new ReleaseException('GitHub request could not be encoded.', previous: $exception);
            }
            $headers[] = 'Content-Type: application/json';
        }

        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content ?? '',
            'ignore_errors' => true,
            'timeout' => 30,
        ]]);
        $http_response_header = [];
        $raw = @file_get_contents(rtrim($this->apiUrl, '/').$path, false, $context);
        $status = $this->status($http_response_header);

        if ($raw === false && $status === 0) {
            throw new ReleaseException('GitHub API request failed before receiving a response.');
        }

        $data = [];
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new ReleaseException("GitHub API returned invalid JSON for {$method} {$path}.", previous: $exception);
            }
            if (! is_array($decoded)) {
                throw new ReleaseException("GitHub API returned an invalid payload for {$method} {$path}.");
            }
            $data = $decoded;
        }

        if (! in_array($status, $allowed, true)) {
            $message = is_string($data['message'] ?? null) ? ': '.$data['message'] : '';
            throw new ReleaseException("GitHub API {$method} {$path} returned HTTP {$status}{$message}.");
        }

        return new HttpResponse($status, $data);
    }

    /** @param list<string> $headers */
    private function status(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    /** @param array<mixed> $data
     * @return array<mixed>
     */
    private function array(array $data, string $key): array
    {
        if (! isset($data[$key]) || ! is_array($data[$key])) {
            throw new ReleaseException("GitHub API response is missing array field {$key}.");
        }

        return $data[$key];
    }

    /** @param array<mixed> $data */
    private function string(array $data, string $key): string
    {
        if (! isset($data[$key]) || ! is_string($data[$key])) {
            throw new ReleaseException("GitHub API response is missing string field {$key}.");
        }

        return $data[$key];
    }

    /** @param array<mixed> $data */
    private function releaseFrom(array $data): GitHubRelease
    {
        $id = $data['id'] ?? null;
        if (! is_int($id)) {
            throw new ReleaseException('GitHub release response is missing integer field id.');
        }

        return new GitHubRelease(
            $id,
            $this->string($data, 'tag_name'),
            ($data['draft'] ?? false) === true,
            ($data['immutable'] ?? false) === true,
        );
    }
}
