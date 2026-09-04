<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Lifecycle;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use PickeringTech\Harbour\Contracts\WorkspaceVariableResolver;
use PickeringTech\Harbour\Environment\EnvironmentFile;
use PickeringTech\Harbour\Environment\EnvironmentTemplate;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Identity\ContextIdentifier;
use PickeringTech\Harbour\State\WorkspaceState;
use PickeringTech\Harbour\Variables\DefaultVariableResolver;
use PickeringTech\Harbour\Variables\ResolvedVariable;
use PickeringTech\Harbour\Variables\VariableBag;
use PickeringTech\Harbour\Variables\VariableResolutionContext;

final readonly class VariablePipeline
{
    public function __construct(
        private string $workspacePath,
        private ConfigRepository $config,
        private Container $container,
        private EnvironmentTemplate $templates,
        private EnvironmentFile $environmentFile,
        private ContextIdentifier $identifiers,
    ) {}

    public function resolve(WorkspaceState $state, ?string $database, bool $includeProcessEnvironment = false): VariableBag
    {
        $bag = new VariableBag;
        foreach ($state->variables as $name => $value) {
            $bag->put(new ResolvedVariable($name, $value, 'persisted_state'));
        }

        if ($includeProcessEnvironment) {
            $required = array_flip($this->templates->variables($this->templateContents()));
            $existingPath = $this->workspacePath.'/.env';
            if (is_file($existingPath) && ($contents = file_get_contents($existingPath)) !== false) {
                foreach ($this->environmentFile->parse($contents) as $name => $value) {
                    if (isset($required[$name])) {
                        $bag->put(new ResolvedVariable($name, $value, 'existing_environment', false, false));
                    }
                }
            }
            foreach (getenv() ?: [] as $name => $value) {
                if (isset($required[$name])) {
                    $bag->put(new ResolvedVariable($name, $value, 'process_environment', false, false));
                }
            }
        }

        $context = new VariableResolutionContext($state->identity, $this->workspacePath, $this->projectName(), $state->allocations, $database);
        foreach ((new DefaultVariableResolver($this->identifiers))->resolve($context) as $variable) {
            $bag->put($variable);
        }

        $configured = $this->config->get('harbour.variables', []);
        if (is_array($configured)) {
            foreach ($configured as $name => $definition) {
                if (! is_string($name)) {
                    continue;
                }
                if (is_array($definition)) {
                    $value = $definition['value'] ?? '';
                    if (! is_scalar($value)) {
                        throw new HarbourException(ErrorCode::InvalidConfiguration, "Configured variable [{$name}] must be scalar.");
                    }
                    $bag->put(new ResolvedVariable($name, (string) $value, 'project_configuration', ($definition['secret'] ?? false) === true));
                } elseif (is_scalar($definition)) {
                    $bag->put(new ResolvedVariable($name, (string) $definition, 'project_configuration'));
                }
            }
        }

        $resolvers = $this->config->get('harbour.resolvers', []);
        if (is_array($resolvers)) {
            foreach ($resolvers as $resolverClass) {
                if (! is_string($resolverClass)) {
                    continue;
                }
                $resolver = $this->container->make($resolverClass);
                if (! $resolver instanceof WorkspaceVariableResolver) {
                    throw new HarbourException(ErrorCode::InvalidConfiguration, "Variable resolver [{$resolverClass}] does not implement the contract.");
                }
                foreach ($resolver->resolve($context) as $variable) {
                    $bag->put($variable);
                }
            }
        }

        if ($includeProcessEnvironment
            && in_array('APP_KEY', $this->templates->variables($this->templateContents()), true)
            && $bag->get('APP_KEY') === null) {
            $bag->put(new ResolvedVariable(
                'APP_KEY',
                'base64:'.base64_encode(random_bytes(32)),
                'generated_workspace_secret',
                true,
                false,
            ));
        }

        return $bag;
    }

    public function templateContents(): string
    {
        $configured = $this->configuredString('harbour.template', '.env.harbour');
        $path = str_starts_with($configured, '/') ? $configured : $this->workspacePath.'/'.$configured;
        $root = realpath($this->workspacePath);
        $resolved = realpath($path);

        if ($root === false || $resolved === false || ! is_file($resolved) || is_link($path)
            || ($resolved !== $root && ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR))) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, "Harbour environment template [{$path}] is missing or unsafe.");
        }
        $contents = file_get_contents($resolved);

        if ($contents === false) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, "Unable to read Harbour environment template [{$path}].");
        }

        return $contents;
    }

    public function projectName(): string
    {
        $configured = $this->config->get('harbour.project_name');

        return is_string($configured) && trim($configured) !== '' ? $configured : basename($this->workspacePath);
    }

    private function configuredString(string $key, string $default = ''): string
    {
        $value = $this->config->get($key, $default);
        if (! is_string($value)) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, "Configuration [{$key}] must be a string.");
        }

        return $value;
    }
}
