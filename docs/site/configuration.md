# Configuration

`config/harbour.php` is project policy. `.env.harbour` is the only template Harbour renders.

## Environment template

Interpolation deliberately supports one form:

```dotenv
APP_URL=${APP_URL}
DB_DATABASE=${DB_DATABASE}
VITE_PORT=${VITE_PORT}
```

Defaults belong in configuration or variable resolvers. An unresolved placeholder is an error; Harbour never silently replaces it with an empty string.

## Port allocations

```php
'ports' => [
    'strategy' => DefaultPortAllocationStrategy::class,
    'allocations' => [
        'APP_PORT' => ['range' => [8000, 8999]],
        'VITE_PORT' => ['range' => [9000, 9999]],
        'REVERB_PORT' => ['range' => [10000, 10999]],
    ],
],
```

Add another named allocation by adding another range. Reservations are coordinated through Harbour's locked system-level registry and checked against real socket availability.

## Database behavior

```php
'database' => [
    'enabled' => true,
    'connection' => null,
    'sqlite_path' => 'database/harbour.sqlite',
    'migrate' => true,
    'seed' => false,
],
```

With a null connection, Harbour reads `DB_CONNECTION` from the template and then uses Laravel's connection configuration. Normal migrations run by default. Seeding is opt-in.

## Variables

Static values can be configured directly:

```php
'variables' => [
    'AWS_PROFILE' => 'development',
    'API_TOKEN' => ['value' => env('API_TOKEN'), 'secret' => true],
],
```

Complex values should come from classes implementing `WorkspaceVariableResolver`, listed under `resolvers`. Resolver classes are created through Laravel's container and support constructor injection.

## Lifecycle hooks

Hooks are ordered argument arrays whenever possible:

```php
'hooks' => [
    'after_setup' => [
        ['npm', 'install'],
    ],
],
```

They run from the workspace directory with resolved Harbour variables. A non-zero exit stops setup and leaves ownership state available for safe teardown.

## Replaceable strategies

Workspace identity and port allocation strategies are class names resolved through Laravel's service container:

```php
'identity' => ['strategy' => App\Harbour\TicketIdentityStrategy::class],
'ports' => ['strategy' => App\Harbour\TeamPortStrategy::class],
```

See the interfaces in the [source repository](https://github.com/pickeringtech/harbour/tree/main/src/Contracts) for the stable method signatures.
