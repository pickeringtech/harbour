<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Integrations\Worktrunk\WorktrunkIntegration;
use PickeringTech\Harbour\Process\ProcessResult;
use Throwable;

final class WorktrunkConfigurationPropertyTest extends TestCase
{
    use TestTrait;

    public function test_arbitrary_validator_output_cannot_mutate_existing_project_configuration(): void
    {
        $this->forAll(Generators::string())->then(function (string $output): void {
            $workspace = sys_get_temp_dir().'/harbour-worktrunk-property-'.bin2hex(random_bytes(6));
            mkdir($workspace.'/.config', 0700, true);
            $path = $workspace.'/'.WorktrunkIntegration::CONFIGURATION_PATH;
            $original = "# project-owned sentinel\n[list]\nfull = true\n";
            file_put_contents($path, $original);

            try {
                try {
                    (new WorktrunkIntegration($workspace, new ArbitraryWorktrunkRunner($output)))->prepare();
                } catch (Throwable) {
                    // Invalid, malformed, and conflicting validator documents fail closed.
                }

                self::assertSame($original, file_get_contents($path));
            } finally {
                unlink($path);
                rmdir($workspace.'/.config');
                rmdir($workspace);
            }
        });
    }
}

final readonly class ArbitraryWorktrunkRunner implements CommandRunner
{
    public function __construct(private string $projectOutput) {}

    public function run(array $command, string $workingDirectory, array $environment = [], ?callable $output = null): ProcessResult
    {
        if ($command === ['wt', '--version']) {
            return new ProcessResult(0, 'wt v0.76.0');
        }
        if (in_array('--config', $command, true)) {
            return new ProcessResult(0, (string) json_encode(['user' => ['config' => [
                'pre-start' => [
                    ['dependencies' => WorktrunkIntegration::COMPOSER_INSTALL],
                    ['setup' => WorktrunkIntegration::SETUP],
                ],
                'pre-remove' => ['teardown' => WorktrunkIntegration::TEARDOWN],
            ]]], JSON_THROW_ON_ERROR));
        }

        return new ProcessResult(0, $this->projectOutput);
    }
}
