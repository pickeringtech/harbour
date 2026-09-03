<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Variables;

use PickeringTech\Harbour\Contracts\WorkspaceVariableResolver;
use PickeringTech\Harbour\Identity\ContextIdentifier;

final readonly class DefaultVariableResolver implements WorkspaceVariableResolver
{
    public function __construct(private ContextIdentifier $identifiers) {}

    public function resolve(VariableResolutionContext $context): iterable
    {
        $redis = $this->identifiers->redis($context->identity, $context->projectName);
        $cookie = $this->identifiers->cookie($context->identity, $context->projectName);

        yield new ResolvedVariable('HARBOUR_WORKSPACE_ID', $context->identity->id(), self::class);
        yield new ResolvedVariable('HARBOUR_WORKSPACE_SLUG', $context->identity->slug(), self::class);
        yield new ResolvedVariable('REDIS_PREFIX', $redis, self::class);
        yield new ResolvedVariable('CACHE_PREFIX', $redis.'cache:', self::class);
        yield new ResolvedVariable('QUEUE_PREFIX', $redis.'queue:', self::class);
        yield new ResolvedVariable('QUEUE_NAME', rtrim($redis, ':').':queue', self::class);
        yield new ResolvedVariable('REDIS_QUEUE', rtrim($redis, ':').':queue', self::class);
        yield new ResolvedVariable('HORIZON_PREFIX', $redis.'horizon:', self::class);
        yield new ResolvedVariable('SESSION_COOKIE', $cookie, self::class);
        yield new ResolvedVariable('MONGODB_DATABASE', $this->identifiers->database($context->identity, $context->projectName), self::class);
        yield new ResolvedVariable('SEARCH_PREFIX', $this->identifiers->database($context->identity, $context->projectName).'_', self::class);
        yield new ResolvedVariable('OBJECT_STORAGE_BUCKET', $this->identifiers->bucket($context->identity, $context->projectName), self::class);
        yield new ResolvedVariable('VITE_HOT_FILE', $context->workspacePath.'/.harbour/vite/hot', self::class);

        foreach ($context->ports as $name => $port) {
            yield new ResolvedVariable($name, (string) $port, 'port_allocation');
        }

        if (isset($context->ports['APP_PORT'])) {
            yield new ResolvedVariable('APP_URL', 'http://127.0.0.1:'.$context->ports['APP_PORT'], self::class);
        }

        if ($context->database !== null) {
            yield new ResolvedVariable('DB_DATABASE', $context->database, 'database_resource');
        }
    }
}
