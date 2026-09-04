<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Installation\ArtisanWorkspaceStarter;
use PickeringTech\Harbour\Process\ProcessResult;
use Symfony\Component\Process\Process;

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
        $runner = new RecordingStarterRunner(new ProcessResult(0, '{"version":1,"ok":true,"workspace":{"status":"ready","slug":"test"}}'));
        $starter = new ArtisanWorkspaceStarter($this->directory, $runner);

        self::assertSame(
            '{"version":1,"ok":true,"workspace":{"status":"ready","slug":"test"}}',
            $starter->start(),
        );
        self::assertSame([PHP_BINARY, $this->directory.'/artisan', 'workspace:setup', '--json'], $runner->command);
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
            self::assertSame(['exit_code' => 23, 'stderr' => 'Docker unavailable'], $exception->context);
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

    public function test_it_clears_cached_configuration_before_starting(): void
    {
        mkdir($this->directory.'/bootstrap/cache', 0700, true);
        file_put_contents($this->directory.'/bootstrap/cache/config.php', '<?php return [];');
        $runner = new SequenceStarterRunner([
            new ProcessResult(0, 'Configuration cache cleared.'),
            new ProcessResult(0, '{"version":1,"ok":true,"workspace":{"status":"ready","slug":"test"}}'),
        ]);

        $result = json_decode((new ArtisanWorkspaceStarter($this->directory, $runner))->start(), true);

        self::assertIsArray($result);
        self::assertIsArray($result['workspace']);
        self::assertSame('ready', $result['workspace']['status']);
        self::assertSame([PHP_BINARY, $this->directory.'/artisan', 'config:clear'], $runner->commands[0]);
        self::assertSame([PHP_BINARY, $this->directory.'/artisan', 'workspace:setup', '--json'], $runner->commands[1]);
        @unlink($this->directory.'/bootstrap/cache/config.php');
        @rmdir($this->directory.'/bootstrap/cache');
        @rmdir($this->directory.'/bootstrap');
    }

    public function test_it_rejects_an_invalid_setup_status_payload(): void
    {
        $starter = new ArtisanWorkspaceStarter($this->directory, new RecordingStarterRunner(new ProcessResult(0, 'not json')));

        try {
            $starter->start();
            self::fail('An invalid status payload must fail installation startup.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::ProcessFailed, $exception->errorCode);
            self::assertStringContainsString('valid workspace status', $exception->getMessage());
        }
    }

    public function test_it_extracts_the_last_valid_setup_payload_after_stdout_noise(): void
    {
        $output = "Deprecated: package warning\n{\"ok\":true,\"workspace\":{\"slug\":\"first\"}}\nnoise\n{\"version\":1,\"ok\":true,\"workspace\":{\"slug\":\"last\"}}\n";

        self::assertSame('last', ArtisanWorkspaceStarter::workspaceFromOutput($output)['slug']);

        $runner = new RecordingStarterRunner(new ProcessResult(0, $output));
        (new ArtisanWorkspaceStarter($this->directory, $runner))->start(static function (string $type, string $buffer): void {});
        self::assertContains('--stream', $runner->command);
    }

    public function test_human_streaming_forwards_service_stderr_but_hides_the_internal_json_protocol(): void
    {
        $payload = '{"version":1,"ok":true,"workspace":{"slug":"test"}}';
        $runner = new StreamingStarterRunner(new ProcessResult(0, $payload));
        $seen = '';

        (new ArtisanWorkspaceStarter($this->directory, $runner))->start(
            static function (string $type, string $buffer) use (&$seen): void {
                $seen .= $buffer;
            },
        );

        self::assertSame("Container pgsql Healthy\n", $seen);
        self::assertStringNotContainsString('"workspace"', $seen);
    }

    public function test_it_reports_config_clear_stderr_and_rejects_a_symlinked_cache(): void
    {
        mkdir($this->directory.'/bootstrap/cache', 0700, true);
        file_put_contents($this->directory.'/bootstrap/cache/config.php', '<?php return [];');
        $starter = new ArtisanWorkspaceStarter(
            $this->directory,
            new SequenceStarterRunner([new ProcessResult(1, '', 'cache clear failed')]),
        );

        try {
            $starter->start();
            self::fail('A failed config clear must stop startup.');
        } catch (HarbourException $exception) {
            self::assertSame('cache clear failed', $exception->context['stderr']);
        }

        unlink($this->directory.'/bootstrap/cache/config.php');
        symlink($this->directory.'/artisan', $this->directory.'/bootstrap/cache/config.php');
        try {
            $starter->start();
            self::fail('A symlinked cache must be rejected.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::UnsafeOperation, $exception->errorCode);
        } finally {
            unlink($this->directory.'/bootstrap/cache/config.php');
            rmdir($this->directory.'/bootstrap/cache');
            rmdir($this->directory.'/bootstrap');
        }
    }
}

final class RecordingStarterRunner implements CommandRunner
{
    /** @var list<string> */
    public array $command = [];

    public string $workingDirectory = '';

    public function __construct(private readonly ProcessResult $result) {}

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function run(array $command, string $workingDirectory, array $environment = [], ?callable $output = null): ProcessResult
    {
        $this->command = $command;
        $this->workingDirectory = $workingDirectory;

        return $this->result;
    }
}

final class SequenceStarterRunner implements CommandRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    /** @param list<ProcessResult> $results */
    public function __construct(private array $results) {}

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function run(array $command, string $workingDirectory, array $environment = [], ?callable $output = null): ProcessResult
    {
        $this->commands[] = $command;

        return array_shift($this->results) ?? new ProcessResult(1, '', 'Missing test result.');
    }
}

final class StreamingStarterRunner implements CommandRunner
{
    public function __construct(private readonly ProcessResult $result) {}

    public function run(array $command, string $workingDirectory, array $environment = [], ?callable $output = null): ProcessResult
    {
        if ($output !== null) {
            $output(Process::ERR, "Container pgsql Healthy\n");
            $output(Process::OUT, $this->result->output."\n");
        }

        return $this->result;
    }
}
