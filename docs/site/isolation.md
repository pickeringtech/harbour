# Isolation

Harbour shares infrastructure where Laravel supports safe logical boundaries and creates physical resources only where necessary.

## Application and development ports

`APP_PORT`, `VITE_PORT`, and `REVERB_PORT` are allocated independently. `APP_URL` is built from the application port. Projects can add arbitrary named allocations.

## Databases

PostgreSQL and MySQL/MariaDB workspaces receive distinct, safely quoted database names and random ownership markers. SQLite receives a workspace-local file constrained to the checkout.

Teardown verifies both persisted state and the ownership marker before dropping a server database. A configured database name alone is never sufficient authority.

## Redis, cache, and locks

Each workspace receives `REDIS_PREFIX` and `CACHE_PREFIX`. Laravel cache keys and cache-backed locks therefore occupy separate keyspaces when the project template connects those variables to Laravel's standard configuration.

## Sessions

`SESSION_COOKIE` is unique per workspace. This matters because browser cookies are scoped by hostname rather than port: two applications on `127.0.0.1` with different ports would otherwise share the same cookie name.

## Queues and Horizon

`QUEUE_NAME`, `REDIS_QUEUE`, `QUEUE_PREFIX`, and `HORIZON_PREFIX` are workspace-specific. Queue workers must start with the rendered workspace environment so they listen to that workspace's queue.

Harbour configures workers; it does not supervise them.

## Vite

`VITE_PORT` avoids HMR port collisions. Laravel's default `public/hot` file is already inside each checkout, so normal worktrees do not need a custom hot file.

See [Vite and Reverb](/vite-and-reverb/) for process commands and advanced custom hot-file support.

## Reverb

`REVERB_PORT` gives each checkout a safe listener port. Harbour does not run Reverb; launch it normally after loading the workspace environment.
