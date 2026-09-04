<?php

declare(strict_types=1);

namespace PickeringTech\Harbour;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;

final readonly class HarbourConfig
{
    /**
     * @param  array<string, array{range: array{int, int}}>  $portAllocations
     * @param  array<string, array{value: string, secret: bool}>  $variables
     * @param  list<string>  $resolvers
     * @param  array<string, array<string, mixed>>  $services
     * @param  array<string, array<string, mixed>>  $compose
     * @param  array<string, list<list<string>>>  $hooks
     */
    public function __construct(
        public bool $enabled,
        public string $template,
        public string $stateFilename,
        public ?string $projectName,
        public string $installationProvider,
        public array $portAllocations,
        public bool $databaseEnabled,
        public ?string $databaseConnection,
        public string $databaseSqlitePath,
        public bool $databaseMigrate,
        public bool $databaseSeed,
        public array $variables,
        public array $resolvers,
        public array $services,
        public array $compose,
        public array $hooks,
    ) {}

    public static function fromRepository(ConfigRepository $config): self
    {
        $template = $config->get('harbour.template', '.env.harbour');
        $state = $config->get('harbour.state', '.harbour.json');
        $projectName = $config->get('harbour.project_name');
        $provider = $config->get('harbour.installation.provider', 'shared');
        $connection = $config->get('harbour.database.connection');
        $sqlitePath = $config->get('harbour.database.sqlite_path', 'database/harbour.sqlite');

        if (! is_string($template)) {
            throw self::invalid('Configuration [harbour.template] must be a string.');
        }
        if (! is_string($state) || preg_match('/\A[A-Za-z0-9._-]+\z/', $state) !== 1) {
            throw self::invalid('Harbour state must be a safe workspace-local filename.');
        }
        if ($projectName !== null && ! is_string($projectName)) {
            throw self::invalid('Configuration [harbour.project_name] must be a string or null.');
        }
        if (! is_string($provider) || ! in_array($provider, ['shared', 'compose'], true)) {
            throw self::invalid('Harbour installation provider must be shared or compose.');
        }
        if ($connection !== null && ! is_string($connection)) {
            throw self::invalid('Harbour database connection must be a string or null.');
        }
        if (! is_string($sqlitePath)) {
            throw self::invalid('Harbour SQLite path must be a string.');
        }

        $services = self::configurationMap(
            self::array($config, 'harbour.services', 'Harbour services must be an array.'),
            'Service ports require a named two-value range.',
        );
        $compose = self::configurationMap(
            self::array($config, 'harbour.compose', 'Harbour Compose projects must be an array.'),
            'Service ports require a named two-value range.',
        );

        return new self(
            (bool) $config->get('harbour.enabled', false),
            $template,
            $state,
            $projectName,
            $provider,
            self::portAllocations(self::array($config, 'harbour.ports.allocations', 'Port allocations must be an array.')),
            (bool) $config->get('harbour.database.enabled', true),
            $connection,
            $sqlitePath,
            (bool) $config->get('harbour.database.migrate', true),
            (bool) $config->get('harbour.database.seed', false),
            self::variables(self::array($config, 'harbour.variables', 'Harbour variables must be an array.')),
            self::resolvers(self::array($config, 'harbour.resolvers', 'Harbour resolvers must be an array.')),
            $services,
            $compose,
            self::hooks(self::array($config, 'harbour.hooks', 'Harbour hooks must be an array.')),
        );
    }

    /**
     * @param  array<mixed>  $allocations
     * @return array<string, array{range: array{int, int}}>
     */
    private static function portAllocations(array $allocations): array
    {
        $normalized = [];
        foreach ($allocations as $name => $definition) {
            if (! is_string($name) || ! is_array($definition)) {
                throw self::invalid('Each port allocation requires a two-value range.');
            }
            $normalized[$name] = ['range' => self::portRange($definition['range'] ?? null, 'Each port allocation requires a two-value range.')];
        }

        return $normalized;
    }

    /**
     * @param  array<mixed>  $configurations
     * @return array<string, array<string, mixed>>
     */
    private static function configurationMap(array $configurations, string $portMessage): array
    {
        $normalized = [];
        foreach ($configurations as $name => $configuration) {
            // Preserve the historical tolerance for unrelated numeric entries
            // and incomplete optional definitions by leaving them inactive.
            if (! is_string($name) || ! is_array($configuration)) {
                continue;
            }

            $entry = [];
            foreach ($configuration as $key => $value) {
                if (! is_string($key)) {
                    throw self::invalid('Configuration object keys must be strings.');
                }
                $entry[$key] = $value;
            }
            if (array_key_exists('ports', $entry)) {
                if (! is_array($entry['ports'])) {
                    throw self::invalid($portMessage);
                }
                $ports = [];
                foreach ($entry['ports'] as $variable => $definition) {
                    if (! is_string($variable) || ! is_array($definition)) {
                        throw self::invalid($portMessage);
                    }
                    $ports[$variable] = [
                        ...$definition,
                        'range' => self::portRange($definition['range'] ?? null, $portMessage),
                    ];
                }
                $entry['ports'] = $ports;
            }
            $normalized[$name] = $entry;
        }

        return $normalized;
    }

    /**
     * @param  array<mixed>  $variables
     * @return array<string, array{value: string, secret: bool}>
     */
    private static function variables(array $variables): array
    {
        $normalized = [];
        foreach ($variables as $name => $definition) {
            if (! is_string($name)) {
                continue;
            }
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                throw self::invalid("Configured variable [{$name}] has an invalid name.");
            }
            if (is_array($definition)) {
                $value = $definition['value'] ?? '';
                if (! is_scalar($value)) {
                    throw self::invalid("Configured variable [{$name}] must be scalar.");
                }
                $normalized[$name] = ['value' => (string) $value, 'secret' => ($definition['secret'] ?? false) === true];
            } elseif (is_scalar($definition)) {
                $normalized[$name] = ['value' => (string) $definition, 'secret' => false];
            }
        }

        return $normalized;
    }

    /**
     * @param  array<mixed>  $resolvers
     * @return list<string>
     */
    private static function resolvers(array $resolvers): array
    {
        return array_values(array_filter($resolvers, is_string(...)));
    }

    /**
     * @param  array<mixed>  $hooks
     * @return array<string, list<list<string>>>
     */
    private static function hooks(array $hooks): array
    {
        $normalized = [];
        foreach ($hooks as $stage => $commands) {
            if (! is_string($stage) || ! is_array($commands)) {
                throw self::invalid(is_string($stage)
                    ? "Lifecycle hooks [{$stage}] must be an array."
                    : 'Lifecycle hook stage names must be strings.');
            }
            $normalized[$stage] = [];
            foreach ($commands as $command) {
                if (is_string($command)) {
                    $normalized[$stage][] = ['/bin/sh', '-c', $command];

                    continue;
                }
                if (! is_array($command) || ! array_is_list($command)) {
                    throw self::invalid("Invalid lifecycle hook in [{$stage}].");
                }
                $arguments = [];
                foreach ($command as $argument) {
                    if (! is_string($argument)) {
                        throw self::invalid("Invalid lifecycle hook in [{$stage}].");
                    }
                    $arguments[] = $argument;
                }
                $normalized[$stage][] = $arguments;
            }
        }

        return $normalized;
    }

    /** @return array{int, int} */
    private static function portRange(mixed $range, string $message): array
    {
        if (! is_array($range) || count($range) !== 2) {
            throw self::invalid($message);
        }
        $minimum = $range[0] ?? null;
        $maximum = $range[1] ?? null;
        if ((! is_int($minimum) && ! (is_string($minimum) && ctype_digit($minimum)))
            || (! is_int($maximum) && ! (is_string($maximum) && ctype_digit($maximum)))) {
            throw self::invalid('Port range bounds must be integers.');
        }

        return [(int) $minimum, (int) $maximum];
    }

    /** @return array<mixed> */
    private static function array(ConfigRepository $config, string $key, string $message): array
    {
        $value = $config->get($key, []);
        if (! is_array($value)) {
            throw self::invalid($message);
        }

        return $value;
    }

    private static function invalid(string $message): HarbourException
    {
        return new HarbourException(ErrorCode::InvalidConfiguration, $message);
    }
}
