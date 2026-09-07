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
        $process = $this->runChecker($this->clover(20, 20));

        self::assertSame(0, $process->getExitCode());
        self::assertStringContainsString('Statement coverage: 100.00% (20/20); required: 100.00%', $process->getOutput());
        self::assertStringContainsString('Coverage threshold met.', $process->getOutput());
    }

    public function test_any_uncovered_statement_fails_even_when_the_display_rounds_to_one_hundred_percent(): void
    {
        $process = $this->runChecker($this->clover(20_001, 20_000));

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Statement coverage: 100.00% (20000/20001); required: 100.00%', $process->getErrorOutput());
        self::assertStringContainsString('Coverage threshold not met.', $process->getErrorOutput());
    }

    public function test_missing_empty_malformed_and_invalid_metric_reports_are_configuration_errors(): void
    {
        foreach (['', 'not XML', $this->clover(0, 0), $this->cloverAttributes('', '0'), $this->cloverAttributes('1', '2')] as $report) {
            $process = $this->runChecker($report);
            self::assertSame(2, $process->getExitCode());
        }

        $process = new Process([PHP_BINARY, $this->checker(), $this->directory.'/missing.xml']);
        $process->run();
        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('does not exist', $process->getErrorOutput());
    }

    public function test_the_canonical_command_ci_and_checker_share_the_composer_policy(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $workflow = (string) file_get_contents($root.'/.github/workflows/ci.yml');
        self::assertIsArray($composer);
        $extra = $composer['extra'] ?? null;
        $scripts = $composer['scripts'] ?? null;
        self::assertIsArray($extra);
        self::assertIsArray($scripts);
        $harbour = $extra['harbour'] ?? null;
        self::assertIsArray($harbour);

        self::assertSame(100, $harbour['coverage-minimum'] ?? null);
        self::assertSame([
            '@php tools/check-coverage-driver.php',
            'phpunit --coverage-clover build/coverage.xml --coverage-text',
            '@php tools/check-coverage.php build/coverage.xml',
        ], $scripts['test'] ?? null);
        self::assertSame('@test', $scripts['coverage'] ?? null);
        self::assertMatchesRegularExpression('/coverage:\n(?:(?!\n  \S).)*?- run: composer test\n/s', $workflow);
        self::assertStringNotContainsString('composer coverage', $workflow);
    }

    public function test_the_driver_preflight_fails_early_without_a_coverage_extension(): void
    {
        $process = new Process([PHP_BINARY, '-n', dirname(__DIR__, 2).'/tools/check-coverage-driver.php']);
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertSame(
            "Coverage is unavailable: install and enable PCOV or Xdebug with coverage mode.\n",
            $process->getErrorOutput(),
        );
    }

    private function runChecker(string $report): Process
    {
        $path = $this->directory.'/coverage.xml';
        file_put_contents($path, $report);
        $process = new Process([PHP_BINARY, $this->checker(), $path]);
        $process->run();

        return $process;
    }

    private function checker(): string
    {
        return dirname(__DIR__, 2).'/tools/check-coverage.php';
    }

    private function clover(int $statements, int $covered): string
    {
        return $this->cloverAttributes((string) $statements, (string) $covered);
    }

    private function cloverAttributes(string $statements, string $covered): string
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
