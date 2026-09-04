<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Installation\ComposerDependencyInstaller;
use PickeringTech\Harbour\Installation\InstallationRequirement;
use PickeringTech\Harbour\Process\ProcessResult;

final class ComposerDependencyInstallerTest extends TestCase
{
    public function test_it_installs_runtime_and_development_packages_in_two_transparent_commands(): void
    {
        $runner = new RecordingDependencyRunner;
        $installer = new ComposerDependencyInstaller('/tmp/project', $runner);

        $installer->install([
            new InstallationRequirement('package:laravel/scout', 'Scout', 'search', 'install it'),
            new InstallationRequirement('package:laravel/scout', 'Scout', 'search', 'install it'),
            new InstallationRequirement('extension:redis', 'Redis', 'cache', 'install it'),
            new InstallationRequirement('package:laravel/dusk', 'Dusk', 'browser tests', 'install it'),
        ]);

        self::assertSame([
            ['composer', 'require', 'laravel/scout', '--no-interaction'],
            ['composer', 'require', '--dev', 'laravel/dusk', '--no-interaction'],
        ], $runner->commands);
    }

    public function test_it_reports_composer_failure_with_a_stable_error(): void
    {
        $runner = new RecordingDependencyRunner(new ProcessResult(2, '', 'dependency conflict'));
        $installer = new ComposerDependencyInstaller('/tmp/project', $runner);

        try {
            $installer->install([new InstallationRequirement('package:laravel/scout', 'Scout', 'search', 'install it')]);
            self::fail('Expected Composer failure.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::ProcessFailed, $exception->errorCode);
            self::assertSame('dependency conflict', $exception->context['stderr']);
            self::assertSame(['composer', 'require', 'laravel/scout', '--no-interaction'], $exception->context['command']);
        }
    }
}

final class RecordingDependencyRunner implements CommandRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    public function __construct(private readonly ProcessResult $result = new ProcessResult(0, 'installed')) {}

    public function run(array $command, string $workingDirectory, array $environment = [], ?callable $output = null): ProcessResult
    {
        $this->commands[] = $command;

        return $this->result;
    }
}
