<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Integration;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Vite;
use PHPUnit\Framework\Attributes\DataProvider;
use PickeringTech\Harbour\Contracts\PortAllocationStrategy;
use PickeringTech\Harbour\Contracts\WorkspaceIdentityStrategy;
use PickeringTech\Harbour\Contracts\WorkspaceStateRepository;
use PickeringTech\Harbour\Environment\EnvironmentManager;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Facades\Harbour;
use PickeringTech\Harbour\HarbourServiceProvider;
use PickeringTech\Harbour\Ports\FilePortRegistry;
use PickeringTech\Harbour\Tests\TestCase;
use PickeringTech\Harbour\WorkspaceManager;
use stdClass;

final class ServiceProviderCoverageTest extends TestCase
{
    #[DataProvider('invalidBindings')]
    public function test_invalid_strategy_and_state_bindings_are_rejected(string $binding, string $configKey, mixed $value): void
    {
        $this->application()->make(Repository::class)->set($configKey, $value);
        $this->application()->forgetInstance($binding);

        try {
            $this->application()->make($binding);
            self::fail('Invalid service-provider configuration must be rejected.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::InvalidConfiguration, $exception->errorCode);
        }
    }

    /** @return iterable<string, array{class-string, string, mixed}> */
    public static function invalidBindings(): iterable
    {
        yield 'identity value type' => [WorkspaceIdentityStrategy::class, 'harbour.identity.strategy', 123];
        yield 'identity contract' => [WorkspaceIdentityStrategy::class, 'harbour.identity.strategy', stdClass::class];
        yield 'port value type' => [PortAllocationStrategy::class, 'harbour.ports.strategy', 123];
        yield 'port contract' => [PortAllocationStrategy::class, 'harbour.ports.strategy', stdClass::class];
        yield 'state filename' => [WorkspaceStateRepository::class, 'harbour.state', '../outside.json'];
    }

    public function test_invalid_workspace_path_is_rejected(): void
    {
        $this->application()->make(Repository::class)->set('harbour.workspace_path', $this->workspaceDirectory.'/missing');
        $this->application()->forgetInstance(EnvironmentManager::class);

        try {
            $this->application()->make(EnvironmentManager::class);
            self::fail('A missing workspace path must be rejected.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::InvalidConfiguration, $exception->errorCode);
        }
    }

    public function test_registry_location_obeys_xdg_home_and_safe_fallback_precedence(): void
    {
        $config = $this->application()->make(Repository::class);
        $config->set('harbour.registry.path', null);
        $originalXdg = getenv('XDG_STATE_HOME');
        $originalHome = getenv('HOME');

        try {
            putenv('XDG_STATE_HOME='.$this->workspaceDirectory.'/xdg');
            putenv('HOME='.$this->workspaceDirectory.'/home');
            $this->application()->forgetInstance(FilePortRegistry::class);
            self::assertInstanceOf(FilePortRegistry::class, $this->application()->make(FilePortRegistry::class));

            putenv('XDG_STATE_HOME');
            $this->application()->forgetInstance(FilePortRegistry::class);
            self::assertInstanceOf(FilePortRegistry::class, $this->application()->make(FilePortRegistry::class));

            putenv('HOME');
            $this->application()->forgetInstance(FilePortRegistry::class);
            self::assertInstanceOf(FilePortRegistry::class, $this->application()->make(FilePortRegistry::class));
        } finally {
            $this->restoreEnvironment('XDG_STATE_HOME', $originalXdg);
            $this->restoreEnvironment('HOME', $originalHome);
        }
    }

    public function test_facade_exposes_the_injectable_manager_api(): void
    {
        $manager = Harbour::getFacadeRoot();
        self::assertInstanceOf(WorkspaceManager::class, $manager);
        $status = $manager->status();

        self::assertSame(1, $status['version']);
        self::assertSame('absent', $status['workspace']['status']);
    }

    public function test_vite_uses_its_workspace_local_default_and_honours_an_explicit_hot_file(): void
    {
        $vite = $this->application()->make(Vite::class);
        self::assertSame(public_path('/hot'), $vite->hotFile());

        $hotFile = $this->workspaceDirectory.'/.harbour/vite/hot';
        $config = $this->application()->make(Repository::class);
        $config->set('harbour.vite.hot_file', $hotFile);

        try {
            (new HarbourServiceProvider($this->application()))->boot();
            self::assertSame($hotFile, $vite->hotFile());
        } finally {
            $config->set('harbour.vite.hot_file', null);
            $vite->useHotFile(public_path('/hot'));
        }
    }

    private function restoreEnvironment(string $name, string|false $value): void
    {
        putenv($value === false ? $name : $name.'='.$value);
    }
}
