<?php

declare(strict_types=1);

namespace PickeringTech\Harbour;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Vite;
use Illuminate\Support\ServiceProvider;
use PickeringTech\Harbour\Console\DebugCommand;
use PickeringTech\Harbour\Console\DevCommand;
use PickeringTech\Harbour\Console\EnvironmentCommand;
use PickeringTech\Harbour\Console\InstallCommand;
use PickeringTech\Harbour\Console\RenderCommand;
use PickeringTech\Harbour\Console\SetupCommand;
use PickeringTech\Harbour\Console\StatusCommand;
use PickeringTech\Harbour\Console\TeardownCommand;
use PickeringTech\Harbour\Contracts\ApplicationLauncher;
use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Contracts\InstallationDependencyInstaller;
use PickeringTech\Harbour\Contracts\InstallationPreflight;
use PickeringTech\Harbour\Contracts\InstalledApplicationLauncher;
use PickeringTech\Harbour\Contracts\InstalledWorkspaceStarter;
use PickeringTech\Harbour\Contracts\PortAllocationStrategy;
use PickeringTech\Harbour\Contracts\WorkspaceIdentityStrategy;
use PickeringTech\Harbour\Contracts\WorkspaceStateRepository;
use PickeringTech\Harbour\Database\DatabaseManager;
use PickeringTech\Harbour\Database\MySqlDatabaseDriver;
use PickeringTech\Harbour\Database\PostgreSqlDatabaseDriver;
use PickeringTech\Harbour\Database\SqliteDatabaseDriver;
use PickeringTech\Harbour\Docker\ComposeManager;
use PickeringTech\Harbour\Docker\DockerManager;
use PickeringTech\Harbour\Environment\EnvironmentFile;
use PickeringTech\Harbour\Environment\EnvironmentManager;
use PickeringTech\Harbour\Environment\EnvironmentTemplate;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Hooks\LifecycleHookRunner;
use PickeringTech\Harbour\Identity\ContextIdentifier;
use PickeringTech\Harbour\Identity\DefaultWorkspaceIdentityStrategy;
use PickeringTech\Harbour\Installation\ArtisanApplicationLauncher;
use PickeringTech\Harbour\Installation\ArtisanWorkspaceStarter;
use PickeringTech\Harbour\Installation\ComposerDependencyInstaller;
use PickeringTech\Harbour\Installation\ProjectConfigurationDetector;
use PickeringTech\Harbour\Installation\ProjectInstaller;
use PickeringTech\Harbour\Installation\SystemInstallationPreflight;
use PickeringTech\Harbour\Lifecycle\DatabaseLifecycle;
use PickeringTech\Harbour\Lifecycle\ManagedInfrastructure;
use PickeringTech\Harbour\Lifecycle\SetupSequence;
use PickeringTech\Harbour\Lifecycle\TeardownSequence;
use PickeringTech\Harbour\Lifecycle\VariablePipeline;
use PickeringTech\Harbour\Ports\DefaultPortAllocationStrategy;
use PickeringTech\Harbour\Ports\FilePortRegistry;
use PickeringTech\Harbour\Process\ApplicationProcessPlan;
use PickeringTech\Harbour\Process\ForegroundApplicationLauncher;
use PickeringTech\Harbour\Process\SymfonyCommandRunner;
use PickeringTech\Harbour\State\FileWorkspaceStateRepository;
use PickeringTech\Harbour\Support\LifecycleLock;

final class HarbourServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/harbour.php', 'harbour');

        $this->app->singleton(HarbourConfig::class, fn (Application $app): HarbourConfig => HarbourConfig::fromRepository($app->make(ConfigRepository::class)));
        $this->app->singleton(FilePortRegistry::class, fn (Application $app): FilePortRegistry => new FilePortRegistry($this->registryPath($app)));
        $this->app->singleton(CommandRunner::class, SymfonyCommandRunner::class);
        $this->app->singleton(ApplicationProcessPlan::class, fn (Application $app): ApplicationProcessPlan => new ApplicationProcessPlan($this->workspacePath($app)));
        $this->app->singleton(ApplicationLauncher::class, fn (Application $app): ApplicationLauncher => new ForegroundApplicationLauncher(
            $this->workspacePath($app),
            $app->make(ApplicationProcessPlan::class),
            $app->make(CommandRunner::class),
        ));
        $this->app->singleton(WorkspaceIdentityStrategy::class, function (Application $app): WorkspaceIdentityStrategy {
            $class = $app->make(ConfigRepository::class)->get('harbour.identity.strategy', DefaultWorkspaceIdentityStrategy::class);
            if (! is_string($class)) {
                throw new HarbourException(ErrorCode::InvalidConfiguration, 'Configured identity strategy must be a class name.');
            }
            $strategy = $app->make($class);
            if (! $strategy instanceof WorkspaceIdentityStrategy) {
                throw new HarbourException(ErrorCode::InvalidConfiguration, 'Configured identity strategy does not implement WorkspaceIdentityStrategy.');
            }

            return $strategy;
        });
        $this->app->singleton(PortAllocationStrategy::class, function (Application $app): PortAllocationStrategy {
            $class = $app->make(ConfigRepository::class)->get('harbour.ports.strategy', DefaultPortAllocationStrategy::class);
            if (! is_string($class)) {
                throw new HarbourException(ErrorCode::InvalidConfiguration, 'Configured port strategy must be a class name.');
            }
            $strategy = $app->make($class);
            if (! $strategy instanceof PortAllocationStrategy) {
                throw new HarbourException(ErrorCode::InvalidConfiguration, 'Configured port strategy does not implement PortAllocationStrategy.');
            }

            return $strategy;
        });
        $this->app->singleton(WorkspaceStateRepository::class, function (Application $app): WorkspaceStateRepository {
            $state = $app->make(HarbourConfig::class)->stateFilename;

            return new FileWorkspaceStateRepository($this->workspacePath($app).'/'.$state);
        });
        $this->app->singleton(EnvironmentManager::class, fn (Application $app): EnvironmentManager => new EnvironmentManager($this->workspacePath($app)));
        $this->app->singleton(ProjectInstaller::class, fn (Application $app): ProjectInstaller => new ProjectInstaller($this->workspacePath($app)));
        $this->app->singleton(InstallationDependencyInstaller::class, fn (Application $app): InstallationDependencyInstaller => new ComposerDependencyInstaller(
            $this->workspacePath($app),
            $app->make(CommandRunner::class),
        ));
        $this->app->singleton(ProjectConfigurationDetector::class, fn (Application $app): ProjectConfigurationDetector => new ProjectConfigurationDetector($this->workspacePath($app)));
        $this->app->singleton(InstallationPreflight::class, fn (Application $app): InstallationPreflight => new SystemInstallationPreflight(
            $app->make(ConfigRepository::class),
            $app->make(CommandRunner::class),
            $this->workspacePath($app),
        ));
        $this->app->singleton(InstalledWorkspaceStarter::class, fn (Application $app): InstalledWorkspaceStarter => new ArtisanWorkspaceStarter(
            $this->workspacePath($app),
            $app->make(CommandRunner::class),
        ));
        $this->app->singleton(InstalledApplicationLauncher::class, fn (Application $app): InstalledApplicationLauncher => new ArtisanApplicationLauncher(
            $this->workspacePath($app),
            $app->make(CommandRunner::class),
        ));
        $this->app->singleton(LifecycleLock::class, fn (Application $app): LifecycleLock => new LifecycleLock($this->workspacePath($app).'/.harbour/locks/lifecycle.lock'));
        $this->app->singleton(DatabaseManager::class, fn (Application $app): DatabaseManager => new DatabaseManager([
            $app->make(PostgreSqlDatabaseDriver::class),
            $app->make(MySqlDatabaseDriver::class),
            $app->make(SqliteDatabaseDriver::class),
        ]));
        $this->app->singleton(VariablePipeline::class, fn (Application $app): VariablePipeline => new VariablePipeline(
            $this->workspacePath($app),
            $app->make(HarbourConfig::class),
            $app,
            $app->make(EnvironmentTemplate::class),
            $app->make(EnvironmentFile::class),
            $app->make(ContextIdentifier::class),
        ));
        $this->app->singleton(LifecycleHookRunner::class, fn (Application $app): LifecycleHookRunner => new LifecycleHookRunner(
            $this->workspacePath($app),
            $app->make(HarbourConfig::class),
            $app->make(CommandRunner::class),
        ));
        $this->app->singleton(ManagedInfrastructure::class, fn (Application $app): ManagedInfrastructure => new ManagedInfrastructure(
            $this->workspacePath($app),
            $app->make(HarbourConfig::class),
            $app->make(WorkspaceStateRepository::class),
            $app->make(DockerManager::class),
            $app->make(ComposeManager::class),
        ));
        $this->app->singleton(DatabaseLifecycle::class, fn (Application $app): DatabaseLifecycle => new DatabaseLifecycle(
            $this->workspacePath($app),
            $app->make(HarbourConfig::class),
            $app->make(ConfigRepository::class),
            $app->make(WorkspaceStateRepository::class),
            $app->make(EnvironmentFile::class),
            $app->make(ContextIdentifier::class),
            $app->make(DatabaseManager::class),
            $app->make(VariablePipeline::class),
        ));
        $this->app->singleton(SetupSequence::class, fn (Application $app): SetupSequence => new SetupSequence(
            $this->workspacePath($app),
            $app->make(HarbourConfig::class),
            $app,
            $app->make(Dispatcher::class),
            $app->make(WorkspaceIdentityStrategy::class),
            $app->make(PortAllocationStrategy::class),
            $app->make(WorkspaceStateRepository::class),
            $app->make(EnvironmentManager::class),
            $app->make(EnvironmentTemplate::class),
            $app->make(VariablePipeline::class),
            $app->make(ManagedInfrastructure::class),
            $app->make(DatabaseLifecycle::class),
            $app->make(LifecycleHookRunner::class),
        ));
        $this->app->singleton(TeardownSequence::class, fn (Application $app): TeardownSequence => new TeardownSequence(
            $app->make(Dispatcher::class),
            $app->make(PortAllocationStrategy::class),
            $app->make(WorkspaceStateRepository::class),
            $app->make(EnvironmentManager::class),
            $app->make(VariablePipeline::class),
            $app->make(ManagedInfrastructure::class),
            $app->make(DatabaseLifecycle::class),
            $app->make(LifecycleHookRunner::class),
        ));
        $this->app->singleton(WorkspaceManager::class, fn (Application $app): WorkspaceManager => new WorkspaceManager(
            workspacePath: $this->workspacePath($app),
            config: $app->make(HarbourConfig::class),
            states: $app->make(WorkspaceStateRepository::class),
            environment: $app->make(EnvironmentManager::class),
            templates: $app->make(EnvironmentTemplate::class),
            variables: $app->make(VariablePipeline::class),
            database: $app->make(DatabaseLifecycle::class),
            setupSequence: $app->make(SetupSequence::class),
            teardownSequence: $app->make(TeardownSequence::class),
            lock: $app->make(LifecycleLock::class),
        ));
    }

    public function boot(): void
    {
        $hotFile = $this->app->make(ConfigRepository::class)->get('harbour.vite.hot_file');
        if (is_string($hotFile) && $hotFile !== '' && class_exists(Vite::class)) {
            $vite = $this->app->make(Vite::class);
            $vite->useHotFile($hotFile);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                SetupCommand::class,
                TeardownCommand::class,
                StatusCommand::class,
                EnvironmentCommand::class,
                RenderCommand::class,
                DebugCommand::class,
                DevCommand::class,
            ]);
            $this->publishes([__DIR__.'/../config/harbour.php' => $this->app->configPath('harbour.php')], 'harbour-config');
            $this->publishes([__DIR__.'/../resources/.env.harbour' => $this->app->basePath('.env.harbour')], 'harbour-environment');
        }
    }

    private function registryPath(Application $app): string
    {
        $configured = $app->make(ConfigRepository::class)->get('harbour.registry.path');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, DIRECTORY_SEPARATOR);
        }
        $xdg = getenv('XDG_STATE_HOME');
        if (is_string($xdg) && $xdg !== '') {
            return rtrim($xdg, DIRECTORY_SEPARATOR).'/harbour';
        }
        $userHome = getenv('HOME');
        if (is_string($userHome) && $userHome !== '') {
            return rtrim($userHome, DIRECTORY_SEPARATOR).'/.local/state/harbour';
        }

        return sys_get_temp_dir().'/harbour-'.(function_exists('posix_getuid') ? posix_getuid() : 'user');
    }

    private function workspacePath(Application $app): string
    {
        $configured = $app->make(ConfigRepository::class)->get('harbour.workspace_path');
        $path = is_string($configured) && $configured !== '' ? $configured : $app->basePath();
        $resolved = realpath($path);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, 'Harbour workspace path must be an existing directory.');
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }
}
