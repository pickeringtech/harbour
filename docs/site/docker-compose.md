# Docker Compose

Compose mode is for a dependency graph that genuinely needs a workspace-specific stack. It does not imply that Laravel itself runs in Compose.

```php
'compose' => [
    'project-stack' => [
        'file' => 'docker-compose.harbour.yml',
        'ports' => [
            'EXAMPLE_SERVICE_PORT' => [
                'range' => [13000, 13999],
            ],
        ],
    ],
],
```

Harbour snapshots the validated Compose file into its private workspace state, supplies resolved variables, and invokes `docker compose` with a collision-resistant project name and explicit argument array.

Teardown validates persisted workspace, project, and Compose ownership evidence before running `down --remove-orphans`. It does not pass `-v`; persistent volumes are retained conservatively. Compose resources declared `external` remain external.

Two workspaces receive different project names, networks, containers, and allocated host ports. Tearing one down cannot target the other's recorded project.

Use [Docker resources](/docker/) for one isolated service and shared mode when a logical database, prefix, bucket, or index is enough.
