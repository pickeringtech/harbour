# Docker Compose

Compose mode is for dependencies that should be started separately for each
workspace. It does not imply that Laravel itself runs in Compose: PHP, Artisan,
Vite, and Node remain native.

## Generate a stack during installation

Run the interactive installer, choose components manually, and answer yes to
**Use Docker Compose for these service-backed components?** Harbour generates a
readable `docker-compose.harbour.yml` containing only the selected services.
The next prompt can set up the workspace and start them immediately.

The same operation is deterministic for agents and CI:

```bash
php artisan workspace:install \
    --database=pgsql \
    --cache=redis \
    --mail=mailpit \
    --with=meilisearch,minio \
    --compose \
    --start \
    --no-interaction
```

Purpose of the two Compose-specific flags:

- `--compose` writes the Compose file and records its allocated port policy.
- `--start` performs the first `workspace:setup`, waits for healthy services,
  creates the isolated database, renders `.env`, and runs migrations.

The generated stack supports Sail's service vocabulary: MySQL, PostgreSQL,
MariaDB, MongoDB, Redis, Valkey, Memcached, Meilisearch, Typesense, MinIO,
RustFS, Mailpit, RabbitMQ, Selenium, and Soketi. Host ports bind to loopback and
are reserved per workspace at setup time.

Every generated image is pinned to a reviewable version and every service has
a readiness healthcheck, so `docker compose up --wait` means ready rather than
merely started. MongoDB uses the lightweight official `mongo` image and is
connection-only at the database level: Harbour owns the optional container,
not the MongoDB database inside it.

Harbour does not overwrite an existing `docker-compose.harbour.yml`. If that
file already exists, it remains project-owned and the installer reports it as
unchanged.

## Supply a project-specific stack

Projects with custom dependencies can configure their own Compose project:

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

Setup uses Compose's detached wait mode and a bounded readiness timeout. The
Compose ownership record is persisted before `up`, so a failed or interrupted
start still leaves enough evidence for `workspace:teardown --force` to clean up
what Compose created.

Teardown validates persisted workspace, project, and Compose ownership evidence before running `down --remove-orphans`. It does not pass `-v`; persistent volumes are retained conservatively. Compose resources declared `external` remain external.

Two workspaces receive different project names, networks, containers, and allocated host ports. Tearing one down cannot target the other's recorded project.

Use [Docker resources](/docker/) for one isolated service and shared mode when a logical database, prefix, bucket, or index is enough.
