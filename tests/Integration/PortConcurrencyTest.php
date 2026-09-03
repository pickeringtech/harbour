<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class PortConcurrencyTest extends TestCase
{
    public function test_twelve_real_processes_receive_distinct_ports(): void
    {
        $directory = sys_get_temp_dir().'/harbour-concurrency-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $processes = [];

        try {
            for ($index = 0; $index < 12; $index++) {
                $workspace = $directory.'/workspace-'.$index;
                mkdir($workspace, 0700);
                $pipes = [];
                $process = proc_open([
                    PHP_BINARY,
                    dirname(__DIR__).'/Fixtures/allocate-port.php',
                    $directory.'/registry',
                    'ws-'.$index,
                    $workspace,
                ], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = [$process, $pipes];
            }

            $ports = [];
            foreach ($processes as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), (string) $error);
                self::assertMatchesRegularExpression('/^\d{5}$/', (string) $output);
                $ports[] = (int) $output;
            }

            self::assertCount(12, array_unique($ports));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
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
