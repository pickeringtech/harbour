<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Installation\ArtisanWorkspaceStarter;
use PickeringTech\Harbour\Process\ProcessResult;

final class ArtisanWorkspaceStarterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/harbour-starter-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        file_put_contents($this->directory.'/artisan', "<?php\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->directory.'/artisan');
        @rmdir($this->directory);
    }

    public function test_it_starts_the_new_workspace_through_its_artisan_entry_point(): void
    {
        $runner = new RecordingStarterRunner(new ProcessResult(0, 'Harbour is ready.'));
        $starter = new ArtisanWorkspaceStarter($this->directory, $runner);

        self::assertSame('Harbour is ready.', $starter->start());
        self::assertSame([PHP_BINARY, $this->directory.'/artisan', 'workspace:setup'], $runner->command);
        self::assertSame($this->directory, $runner->workingDirectory);
    }

    public function test_it_reports_a_retryable_error_without_hiding_the_installation(): void
    {
        $starter = new ArtisanWorkspaceStarter(
            $this->directory,
            new RecordingStarterRunner(new ProcessResult(23, '', 'Docker unavailable')),
        );

        try {
            $starter->start();
            self::fail('Expected workspace startup to fail.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::ProcessFailed, $exception->errorCode);
            self::assertStringContainsString('project files were created', $exception->getMessage());
            self::assertSame(['exit_code' => 23], $exception->context);
        }
    }

    public function test_it_rejects_a_missing_or_symlinked_artisan_file(): void
    {
        unlink($this->directory.'/artisan');
        $starter = new ArtisanWorkspaceStarter($this->directory, new RecordingStarterRunner(new ProcessResult(0, '')));

        try {
            $starter->start();
            self::fail('Expected the missing entry point to fail.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::UnsafeOperation, $exception->errorCode);
        }
    }
}

final class RecordingStarterRunner implements CommandRunner
{
    /** @var list<string> */
    public array $command = [];

    public string $workingDirectory = '';

    public function __construct(private readonly ProcessResult $result) {}

    public function run(array $command, string $workingDirectory, array $environment = []): ProcessResult
    {
        $this->command = $command;
        $this->workingDirectory = $workingDirectory;

        return $this->result;
    }
}
