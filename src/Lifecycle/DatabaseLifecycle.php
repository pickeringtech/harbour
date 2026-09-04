<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Lifecycle;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use PickeringTech\Harbour\Contracts\WorkspaceStateRepository;
use PickeringTech\Harbour\Database\DatabaseConfiguration;
use PickeringTech\Harbour\Database\DatabaseManager;
use PickeringTech\Harbour\Environment\EnvironmentFile;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\HarbourConfig;
use PickeringTech\Harbour\Identity\ContextIdentifier;
use PickeringTech\Harbour\State\OwnedResource;
use PickeringTech\Harbour\State\ResourceType;
use PickeringTech\Harbour\State\WorkspaceState;

final readonly class DatabaseLifecycle
{
    public function __construct(
        private string $workspacePath,
        private HarbourConfig $config,
        private ConfigRepository $laravelConfig,
        private WorkspaceStateRepository $states,
        private EnvironmentFile $environmentFile,
        private ContextIdentifier $identifiers,
        private DatabaseManager $databases,
        private VariablePipeline $variables,
    ) {}

    /** @return array{WorkspaceState, ?string} */
    public function setup(WorkspaceState $state): array
    {
        if (! $this->config->databaseEnabled) {
            return [$state, null];
        }

        $database = $this->resource($state);
        $configuration = $this->configuration(state: $state);
        $databaseName = null;

        if ($database === null) {
            $databaseName = $this->desiredDatabase($configuration, $state);
            $database = $this->databases->prepare($state->identity, $configuration, $databaseName);
            $state = $state->withResource($database);
            $this->states->save($state);
            $database = $this->databases->create($database, $this->workspacePath, $configuration);
            $state = $state->withResource($database);
            $this->states->save($state);
        } else {
            $recordedDatabase = $database->metadata['database'] ?? null;
            if (! is_string($recordedDatabase) || $recordedDatabase === '') {
                throw new HarbourException(ErrorCode::StateCorrupted, 'Database resource has no valid database name.');
            }
            $databaseName = $recordedDatabase;
            if ($database->creationPending()) {
                // prepare() is persisted before create(). A retry may finish that
                // exact operation, but create() still rejects an unowned collision.
                $database = $this->databases->create($database, $this->workspacePath, $configuration);
                $state = $state->withResource($database);
                $this->states->save($state);
            } elseif (! $this->databases->exists($database, $configuration)) {
                throw new HarbourException(
                    ErrorCode::DatabaseNotOwned,
                    'Recorded workspace database is missing or has lost its ownership marker. Run workspace:teardown --force, resolve any name collision, then run workspace:setup.',
                );
            }
        }

        $this->applyToLaravel($configuration, $databaseName);

        return [$state, $databaseName];
    }

    public function destroy(OwnedResource $resource, WorkspaceState $state): void
    {
        $this->databases->destroy($resource, $this->configuration($resource->driver, $state), $this->workspacePath);
    }

    public function resource(WorkspaceState $state): ?OwnedResource
    {
        return $state->resource(ResourceType::Database);
    }

    private function configuration(?string $forceDriver = null, ?WorkspaceState $state = null): DatabaseConfiguration
    {
        $configured = $this->config->databaseConnection;
        if ($forceDriver !== null) {
            $connection = is_string($configured) && $configured !== '' ? $configured : $this->configuredString('database.default');
        } else {
            $templateValues = $this->environmentFile->parse($this->variables->templateContents());
            $connection = is_string($configured) && $configured !== ''
                ? $configured
                : (($templateValues['DB_CONNECTION'] ?? '') !== '' && ! str_contains($templateValues['DB_CONNECTION'], '${')
                    ? $templateValues['DB_CONNECTION']
                    : $this->configuredString('database.default'));
        }
        $data = $this->laravelConfig->get('database.connections.'.$connection, []);

        if (! is_array($data)) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, "Laravel database connection [{$connection}] is not configured.");
        }
        if ($forceDriver !== null) {
            $data['driver'] = $forceDriver;
        }

        if ($state !== null && $this->config->installationProvider === 'compose') {
            $template = $this->environmentFile->parse($this->variables->templateContents());
            foreach ([
                'host' => 'DB_HOST',
                'port' => 'DB_PORT',
                'username' => 'DB_USERNAME',
                'password' => 'DB_PASSWORD',
            ] as $key => $variable) {
                if (isset($template[$variable])) {
                    $data[$key] = $this->managedValue($template[$variable], $state);
                }
            }
            if (($data['driver'] ?? null) === 'pgsql') {
                $data['harbour_admin_database'] = 'postgres';
            }
        }

        return DatabaseConfiguration::fromLaravel($this->stringKeyedArray($data));
    }

    private function desiredDatabase(DatabaseConfiguration $configuration, WorkspaceState $state): string
    {
        if ($configuration->driver === 'sqlite') {
            return $this->workspacePath.'/'.ltrim($this->config->databaseSqlitePath, '/');
        }

        return $this->identifiers->database($state->identity, $this->variables->projectName());
    }

    private function applyToLaravel(DatabaseConfiguration $configuration, string $database): void
    {
        $connection = $this->config->databaseConnection;
        if ($connection === null || $connection === '') {
            $connection = $configuration->driver;
        }
        $this->laravelConfig->set('database.default', $connection);
        foreach (['host', 'port', 'username', 'password'] as $key) {
            if ($configuration->{$key} !== null) {
                $this->laravelConfig->set('database.connections.'.$connection.'.'.$key, $configuration->{$key});
            }
        }
        $this->laravelConfig->set('database.connections.'.$connection.'.database', $database);
    }

    private function managedValue(string $value, WorkspaceState $state): string|int
    {
        if (preg_match('/\A\$\{([A-Z][A-Z0-9_]*)\}\z/', $value, $matches) === 1) {
            $variable = $matches[1];
            $allocation = $state->allocations[$variable] ?? null;
            if (! is_int($allocation)) {
                throw new HarbourException(ErrorCode::InvalidConfiguration, "Managed database variable [{$variable}] has no port allocation.");
            }

            return $allocation;
        }
        if (str_contains($value, '${')) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, 'Managed database connection values must be literal or allocated port variables.');
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new HarbourException(ErrorCode::InvalidConfiguration, 'Configuration object keys must be strings.');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    private function configuredString(string $key): string
    {
        $value = $this->laravelConfig->get($key);
        if (! is_string($value)) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, "Configuration [{$key}] must be a string.");
        }

        return $value;
    }
}
