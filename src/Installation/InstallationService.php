<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Installation;

final readonly class InstallationService
{
    /**
     * @param  list<string>  $aliases
     * @param  array<string, array{container: int, range: array{int, int}, forward: string, discovery: string}>  $ports
     * @param  array<string, string>  $composeEnvironment
     * @param  list<string>  $healthcheck
     * @param  array<string, string>  $composeProperties
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $group,
        public array $aliases,
        public array $ports,
        public string $image,
        public array $composeEnvironment,
        public array $healthcheck,
        public ?string $volume,
        public bool $ownsSqlLifecycle,
        public string $environmentFragment,
        public ?string $command = null,
        public array $composeProperties = [],
    ) {
        $keys = [];
        foreach (preg_split('/\R/', $environmentFragment) ?: [] as $line) {
            $separator = strpos($line, '=');
            if ($separator !== false) {
                $keys[] = substr($line, 0, $separator);
            }
        }
        $this->environmentKeys = array_values(array_unique($keys));
    }

    /** @var list<string> */
    public array $environmentKeys;

    public function primaryPortVariable(): string
    {
        return (string) array_key_first($this->ports);
    }

    public function defaultPort(): int
    {
        return $this->ports[$this->primaryPortVariable()]['container'];
    }
}
