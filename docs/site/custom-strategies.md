# Custom strategies

Major extension points are class names resolved through Laravel's service container, so constructor injection works normally.

## Workspace identity

```php
use PickeringTech\Harbour\Contracts\WorkspaceIdentityStrategy;
use PickeringTech\Harbour\Identity\WorkspaceContext;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;

final class TicketIdentityStrategy implements WorkspaceIdentityStrategy
{
    public function resolve(WorkspaceContext $context): WorkspaceIdentity
    {
        // Return a validated identity with collision-resistant hash evidence.
    }
}
```

```php
'identity' => [
    'strategy' => App\Harbour\TicketIdentityStrategy::class,
],
```

## Port allocation

Implement `PortAllocationStrategy::allocate`, `release`, and `releaseWorkspace` when a team needs a different reservation policy:

```php
'ports' => [
    'strategy' => App\Harbour\TeamPortStrategy::class,
    'allocations' => [
        'APP_PORT' => ['range' => [8000, 8999]],
    ],
],
```

A custom strategy must preserve concurrency, idempotency, workspace ownership, and loopback safety. Returning a merely probed port is not sufficient.

## Variable resolvers

Implement `WorkspaceVariableResolver` for values that depend on other services or project logic:

```php
public function resolve(VariableResolutionContext $context): iterable
{
    yield new ResolvedVariable('TENANT', 'local', self::class);
    yield new ResolvedVariable('API_TOKEN', $this->token, self::class, secret: true);
}
```

Register resolver classes in order under `resolvers`. Later resolvers have higher precedence.
