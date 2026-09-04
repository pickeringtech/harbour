# Redis, cache, sessions, and queues

Harbour uses Laravel's supported configuration points to isolate mutable state on shared services.

## Redis and cache

Each workspace receives `REDIS_PREFIX` and `CACHE_PREFIX`. Redis keys, cache entries, rate-limiter state, and cache-backed locks therefore occupy distinct keyspaces when the project uses Laravel's standard prefix settings.

## Sessions

`SESSION_COOKIE` is unique per workspace. Browser cookies are scoped by hostname, not port, so applications on `127.0.0.1:8001` and `127.0.0.1:8002` need different cookie names.

## Queues

`QUEUE_NAME`, `REDIS_QUEUE`, and `QUEUE_PREFIX` are workspace-specific. Start a worker after loading that workspace's environment:

```bash
eval "$(php artisan workspace:env --format=shell)"
php artisan queue:work redis --queue="$REDIS_QUEUE"
```

The general Redis prefix isolates Redis queue internals; the explicit queue name prevents one workspace's worker from consuming another workspace's jobs.

Database queues isolate through the workspace database. External brokers such as SQS require their own project-specific queue configuration.

## Horizon

Make Horizon's prefix environment-driven if the project does not already do so:

```php
'prefix' => env('HORIZON_PREFIX', 'horizon:'),
```

Harbour does not launch queue workers or Horizon. It supplies safe configuration for whichever process manager starts them.
