# Docker resources

Docker is an optional resource provider, not Harbour's application runtime. PHP, Laravel, Node, and Vite remain native unless the project independently chooses otherwise.

Use a workspace container only when a dependency cannot be isolated safely on shared infrastructure:

```php
'services' => [
    'meilisearch' => [
        'driver' => 'docker',
        'image' => 'getmeili/meilisearch:v1.20',
        'ports' => [
            'MEILISEARCH_PORT' => [
                'container' => 7700,
                'range' => [12000, 12999],
            ],
        ],
        'environment' => [
            'MEILI_ENV' => 'development',
        ],
    ],
],
```

Setup allocates the host port, derives a context-safe container name, invokes Docker with argument arrays, binds only loopback, labels the container with workspace and resource evidence, and persists ownership before startup completes.

Repeated setup reuses a valid recorded container. Teardown inspects Harbour's managed, workspace, and resource labels before removal. A matching name alone is never sufficient.

Keep PostgreSQL, Redis, Mailpit, and similar services shared when their logical namespaces provide adequate isolation. This preserves Harbour's high-density advantage.
