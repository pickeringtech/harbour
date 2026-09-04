<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Ports\FilePortRegistry;
use PickeringTech\Harbour\Ports\PortRequirement;

final class PortRegistryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/harbour-ports-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            is_dir($file) ? @rmdir($file) : @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function test_reservations_are_unique_reused_and_ownership_guarded(): void
    {
        $registry = new FilePortRegistry($this->directory);
        $requirement = new PortRequirement('APP_PORT', 18100, 18109);
        mkdir($this->directory.'/a');
        mkdir($this->directory.'/b');

        $first = $registry->reserve('ws-a', $this->directory.'/a', $requirement);
        $same = $registry->reserve('ws-a', $this->directory.'/a', $requirement);
        $second = $registry->reserve('ws-b', $this->directory.'/b', $requirement);

        self::assertSame($first->port, $same->port);
        self::assertNotSame($first->port, $second->port);
        self::assertFalse($registry->release('ws-b', 'APP_PORT', $first->port));
        self::assertTrue($registry->release('ws-a', 'APP_PORT', $first->port));
        self::assertSame(1, $registry->releaseWorkspace('ws-b'));
        self::assertSame(0, $registry->releaseWorkspace('ws-b'));
    }

    public function test_invalid_ranges_fail_before_registry_mutation(): void
    {
        $this->expectException(HarbourException::class);

        new PortRequirement('APP_PORT', 9000, 8000);
    }

    public function test_a_reused_reservation_is_reallocated_when_its_port_has_been_taken(): void
    {
        $registry = new FilePortRegistry($this->directory);
        $workspace = $this->directory.'/workspace';
        mkdir($workspace);
        $requirement = new PortRequirement('APP_PORT', 18100, 18109);
        $first = $registry->reserve('ws-a', $workspace, $requirement);
        $socket = stream_socket_server('tcp://127.0.0.1:'.$first->port, $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage ?? 'Unable to occupy reserved port.');

        try {
            $second = $registry->reserve('ws-a', $workspace, $requirement);
            self::assertNotSame($first->port, $second->port);
        } finally {
            fclose($socket);
        }
    }

    public function test_malformed_reservation_fields_are_rejected_before_use(): void
    {
        file_put_contents($this->directory.'/ports.json', json_encode([
            'version' => 1,
            'reservations' => [[
                'workspace_id' => 'ws-a',
                'workspace_path' => $this->directory.'/a',
                'name' => 'APP_PORT',
                'host' => '127.0.0.1',
                'port' => '18100',
                'reserved_at' => 'now',
            ]],
        ], JSON_THROW_ON_ERROR));

        $registry = new FilePortRegistry($this->directory);

        try {
            $registry->reserve('ws-b', $this->directory.'/b', new PortRequirement('APP_PORT', 18100, 18109));
            self::fail('Expected a corrupted registry exception.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::StateCorrupted, $exception->errorCode);
        }
    }
}
