<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CoverageThresholdScriptTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/harbour-coverage-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function test_exactly_the_required_percentage_passes(): void
    {
        $process = $this->runChecker($this->clover(20, 19));

        self::assertSame(0, $process->getExitCode());
        self::assertStringContainsString('Line coverage: 95.00% (19/20); required: 95.00%', $process->getOutput());
        self::assertStringContainsString('Coverage threshold met.', $process->getOutput());
    }

    public function test_coverage_below_the_requirement_fails(): void
    {
        $process = $this->runChecker($this->clover(100, 94));

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Coverage threshold not met.', $process->getErrorOutput());
    }

    public function test_invalid_or_empty_reports_are_configuration_errors(): void
    {
        foreach (['not XML', $this->clover(0, 0)] as $report) {
            $process = $this->runChecker($report);
            self::assertSame(2, $process->getExitCode());
        }

        $process = new Process([PHP_BINARY, $this->checker(), $this->directory.'/missing.xml', '95']);
        $process->run();
        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('does not exist', $process->getErrorOutput());
    }

    private function runChecker(string $report): Process
    {
        $path = $this->directory.'/coverage.xml';
        file_put_contents($path, $report);
        $process = new Process([PHP_BINARY, $this->checker(), $path, '95']);
        $process->run();

        return $process;
    }

    private function checker(): string
    {
        return dirname(__DIR__, 2).'/tools/check-coverage.php';
    }

    private function clover(int $statements, int $covered): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <coverage>
              <project>
                <file name="example.php">
                  <metrics statements="{$statements}" coveredstatements="{$covered}"/>
                </file>
              </project>
            </coverage>
            XML;
    }
}
