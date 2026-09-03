<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Identity\ContextIdentifier;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;
use PickeringTech\Harbour\Variables\DefaultVariableResolver;
use PickeringTech\Harbour\Variables\VariableResolutionContext;

final class RedisIsolationTest extends TestCase
{
    public function test_two_workspaces_write_independent_real_redis_keys(): void
    {
        if (getenv('HARBOUR_REDIS_INTEGRATION') !== '1') {
            self::markTestSkipped('Set HARBOUR_REDIS_INTEGRATION=1 to mutate the configured test Redis server.');
        }
        $first = $this->variables('a');
        $second = $this->variables('b');
        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        self::assertNotSame($first['REDIS_PREFIX'], $second['REDIS_PREFIX']);
        self::assertNotSame($first['CACHE_PREFIX'], $second['CACHE_PREFIX']);
        self::assertNotSame($first['SESSION_COOKIE'], $second['SESSION_COOKIE']);
        self::assertNotSame($first['REDIS_QUEUE'], $second['REDIS_QUEUE']);

        $keyA = $first['CACHE_PREFIX'].'same-key';
        $keyB = $second['CACHE_PREFIX'].'same-key';
        $this->redis($host, $port, ['SET', $keyA, 'workspace-a']);
        $this->redis($host, $port, ['SET', $keyB, 'workspace-b']);
        self::assertSame('workspace-a', $this->redis($host, $port, ['GET', $keyA]));
        self::assertSame('workspace-b', $this->redis($host, $port, ['GET', $keyB]));
        $this->redis($host, $port, ['DEL', $keyA, $keyB]);
    }

    /** @return array<string, string> */
    private function variables(string $suffix): array
    {
        $hash = hash('sha256', $suffix);
        $identity = new WorkspaceIdentity('ws_'.$hash, 'workspace-'.$suffix.'-'.substr($hash, 0, 8), $hash, 'feature/'.$suffix);
        $result = [];
        foreach ((new DefaultVariableResolver(new ContextIdentifier))->resolve(
            new VariableResolutionContext($identity, '/tmp/'.$suffix, 'Acme', [], null),
        ) as $variable) {
            $result[$variable->name] = $variable->value;
        }

        return $result;
    }

    /** @param list<string> $arguments */
    private function redis(string $host, int $port, array $arguments): string
    {
        $errorNumber = 0;
        $errorMessage = '';
        $socket = stream_socket_client("tcp://{$host}:{$port}", $errorNumber, $errorMessage, 3);
        self::assertIsResource($socket, $errorMessage ?? 'Unable to connect to Redis.');
        $command = '*'.count($arguments)."\r\n";
        foreach ($arguments as $argument) {
            $command .= '$'.strlen($argument)."\r\n{$argument}\r\n";
        }
        fwrite($socket, $command);
        $line = fgets($socket);
        self::assertIsString($line);
        if (str_starts_with($line, '$')) {
            $length = (int) substr(trim($line), 1);
            $value = stream_get_contents($socket, $length);
            fgets($socket);
            fclose($socket);

            return is_string($value) ? $value : '';
        }
        fclose($socket);

        return trim(substr($line, 1));
    }
}
