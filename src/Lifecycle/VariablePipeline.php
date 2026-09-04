<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Lifecycle;

use Illuminate\Contracts\Container\Container;
use PickeringTech\Harbour\Contracts\WorkspaceVariableResolver;
use PickeringTech\Harbour\Environment\EnvironmentFile;
use PickeringTech\Harbour\Environment\EnvironmentTemplate;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\HarbourConfig;
use PickeringTech\Harbour\Identity\ContextIdentifier;
use PickeringTech\Harbour\State\WorkspaceState;
use PickeringTech\Harbour\Variables\DefaultVariableResolver;
use PickeringTech\Harbour\Variables\ResolvedVariable;
use PickeringTech\Harbour\Variables\VariableBag;
use PickeringTech\Harbour\Variables\VariableResolutionContext;

final class VariablePipeline
{
    private ?string $cachedTemplate = null;

    public function __construct(
        private string $workspacePath,
        private HarbourConfig $config,
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

        $configured = $this->config->variables;
        foreach ($configured as $name => $definition) {
            $bag->put(new ResolvedVariable($name, $definition['value'], 'project_configuration', $definition['secret']));
        }

        $resolvers = $this->config->resolvers;
        if ($includeProcessEnvironment && $resolvers !== []) {
            foreach ($resolvers as $resolverClass) {
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
        if ($this->cachedTemplate !== null) {
            return $this->cachedTemplate;
        }

        $configured = $this->config->template;
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

        return $this->cachedTemplate = $contents;
    }

    public function beginOperation(): void
    {
        $this->cachedTemplate = null;
    }

    public function projectName(): string
    {
        $configured = $this->config->projectName;

        return is_string($configured) && trim($configured) !== '' ? $configured : basename($this->workspacePath);
    }
}
