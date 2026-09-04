<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Integration;

use JsonException;
use PHPUnit\Framework\TestCase;

final class ReleasePolicyIntegrationTest extends TestCase
{
    private const API_VERSION = '2026-03-10';

    public function test_non_bypass_identity_cannot_create_update_or_delete_release_tags(): void
    {
        $this->requireActor('non-bypass');

        self::assertContains($this->createRef($this->createTag(), $this->baseCommit()), [403, 422]);
        self::assertSame(404, $this->ref($this->createTag())['status']);
        self::assertSame($this->baseCommit(), $this->refSha($this->historyTag()));
        self::assertContains($this->updateRef($this->historyTag(), $this->alternateCommit()), [403, 422]);
        self::assertContains($this->deleteRef($this->historyTag()), [403, 422]);
        self::assertSame($this->baseCommit(), $this->refSha($this->historyTag()));
    }

    public function test_owner_identity_retains_emergency_create_update_and_delete_access(): void
    {
        $this->requireActor('owner');

        self::assertSame(201, $this->createRef($this->createTag(), $this->baseCommit()));
        self::assertSame(200, $this->updateRef($this->createTag(), $this->alternateCommit()));
        self::assertSame($this->alternateCommit(), $this->refSha($this->createTag()));
        self::assertSame(204, $this->deleteRef($this->createTag()));
        self::assertSame(404, $this->ref($this->createTag())['status']);
    }

    public function test_release_app_can_create_but_cannot_update_or_delete_release_tags(): void
    {
        $this->requireActor('release-app');

        self::assertSame(201, $this->createRef($this->createTag(), $this->baseCommit()));
        self::assertContains($this->updateRef($this->createTag(), $this->alternateCommit()), [403, 422]);
        self::assertContains($this->deleteRef($this->createTag()), [403, 422]);
        self::assertSame($this->baseCommit(), $this->refSha($this->createTag()));
    }

    private function requireActor(string $expected): void
    {
        if ($this->environment('HARBOUR_RELEASE_POLICY_INTEGRATION', '') !== '1') {
            self::markTestSkipped('Set HARBOUR_RELEASE_POLICY_INTEGRATION=1 to run destructive probes in a disposable repository.');
        }

        $actual = $this->environment('HARBOUR_RELEASE_POLICY_ACTOR', '');
        if ($actual !== $expected) {
            self::markTestSkipped("Probe is configured for the {$actual} actor.");
        }

        self::assertMatchesRegularExpression(
            '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D',
            $this->environment('HARBOUR_RELEASE_POLICY_REPOSITORY'),
        );
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/D', $this->baseCommit());
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/D', $this->alternateCommit());
        self::assertNotSame($this->baseCommit(), $this->alternateCommit());
        self::assertMatchesRegularExpression('/^v[0-9A-Za-z._-]{1,100}$/D', $this->createTag());
        self::assertMatchesRegularExpression('/^v[0-9A-Za-z._-]{1,100}$/D', $this->historyTag());
        self::assertNotSame($this->createTag(), $this->historyTag());
    }

    private function createRef(string $tag, string $commit): int
    {
        return $this->request('POST', '/git/refs', [
            'ref' => 'refs/tags/'.$tag,
            'sha' => $commit,
        ])['status'];
    }

    private function updateRef(string $tag, string $commit): int
    {
        return $this->request('PATCH', '/git/refs/tags/'.rawurlencode($tag), [
            'sha' => $commit,
            'force' => true,
        ])['status'];
    }

    private function deleteRef(string $tag): int
    {
        return $this->request('DELETE', '/git/refs/tags/'.rawurlencode($tag))['status'];
    }

    /** @return array{status: int, data: array<mixed>} */
    private function ref(string $tag): array
    {
        return $this->request('GET', '/git/ref/tags/'.rawurlencode($tag));
    }

    private function refSha(string $tag): string
    {
        $response = $this->ref($tag);
        self::assertSame(200, $response['status']);
        $object = $response['data']['object'] ?? null;
        self::assertIsArray($object);
        $sha = $object['sha'] ?? null;
        self::assertIsString($sha);

        return $sha;
    }

    /**
     * @param  array<string, bool|string>|null  $body
     * @return array{status: int, data: array<mixed>}
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $headers = [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer '.$this->environment('HARBOUR_RELEASE_POLICY_TOKEN'),
            'Content-Type: application/json',
            'User-Agent: harbour-release-policy-test',
            'X-GitHub-Api-Version: '.self::API_VERSION,
        ];
        $content = '';
        if ($body !== null) {
            try {
                $content = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $exception) {
                self::fail('Release policy request body could not be encoded: '.$exception->getMessage());
            }
        }

        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => 30,
        ]]);
        $http_response_header = [];
        $repository = $this->environment('HARBOUR_RELEASE_POLICY_REPOSITORY');
        $raw = @file_get_contents("https://api.github.com/repos/{$repository}{$path}", false, $context);
        $status = $this->httpStatus($http_response_header);

        if ($raw === false && $status === 0) {
            self::fail("GitHub API {$method} {$path} failed before receiving a response.");
        }

        $data = [];
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                self::fail("GitHub API {$method} {$path} returned invalid JSON: ".$exception->getMessage());
            }
            self::assertIsArray($decoded);
            $data = $decoded;
        }

        return ['status' => $status, 'data' => $data];
    }

    /** @param list<string> $headers */
    private function httpStatus(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    private function repositoryValue(string $name): string
    {
        return $this->environment('HARBOUR_RELEASE_POLICY_'.strtoupper($name));
    }

    private function baseCommit(): string
    {
        return $this->repositoryValue('base_commit');
    }

    private function alternateCommit(): string
    {
        return $this->repositoryValue('alternate_commit');
    }

    private function createTag(): string
    {
        return $this->repositoryValue('create_tag');
    }

    private function historyTag(): string
    {
        return $this->repositoryValue('history_tag');
    }

    private function environment(string $name, ?string $default = null): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            if ($default !== null) {
                return $default;
            }

            self::fail("Required integration-test environment variable {$name} is missing.");
        }

        return $value;
    }
}
