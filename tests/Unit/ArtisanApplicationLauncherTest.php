<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Installation\ArtisanApplicationLauncher;
use PickeringTech\Harbour\Process\ProcessResult;

final class ArtisanApplicationLauncherTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/harbour-app-launcher-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        file_put_contents($this->directory.'/artisan', "<?php\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->directory.'/artisan');
        @rmdir($this->directory);
    }

    public function test_it_runs_the_installed_dev_command_and_can_disable_vite(): void
    {
        $runner = new RecordingApplicationRunner;
        $launcher = new ArtisanApplicationLauncher($this->directory, $runner);

        $launcher->launch(false);

        self::assertSame([PHP_BINARY, $this->directory.'/artisan', 'workspace:dev', '--from-install', '--no-vite'], $runner->command);
        self::assertSame($this->directory, $runner->workingDirectory);
    }

    public function test_it_treats_ctrl_c_as_a_clean_attached_session_exit_but_reports_other_failures(): void
    {
        (new ArtisanApplicationLauncher($this->directory, new RecordingApplicationRunner(new ProcessResult(130, '', ''))))->launch();
        self::addToAssertionCount(1);

        try {
            (new ArtisanApplicationLauncher($this->directory, new RecordingApplicationRunner(new ProcessResult(2, '', 'vite failed'))))->launch();
            self::fail('Expected launch failure.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::ProcessFailed, $exception->errorCode);
            self::assertSame('vite failed', $exception->context['stderr']);
        }
    }
}

final class RecordingApplicationRunner implements CommandRunner
{
    /** @var list<string> */
    public array $command = [];

    public string $workingDirectory = '';

    public function __construct(private readonly ProcessResult $result = new ProcessResult(0, '')) {}

    public function run(array $command, string $workingDirectory, array $environment = [], ?callable $output = null): ProcessResult
    {
        $this->command = $command;
        $this->workingDirectory = $workingDirectory;

        return $this->result;
    }
}
