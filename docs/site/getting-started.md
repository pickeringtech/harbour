# Getting started

Harbour has one project-level installation and one repeatable checkout-level setup. The installer makes the choices visible; setup makes each worktree usable.

## Requirements

- PHP 8.4 or newer
- Laravel 13 or newer
- Composer
- Linux or macOS
- the PHP extensions and Laravel client packages required by the components
  you select
- Docker with the Compose v2 plugin only when Compose resources are selected

You do not need to determine that list in advance. `workspace:install` derives
it from the final selected stack and checks it before changing the project.

## 1. Add the package

Run this once in the Laravel project's primary checkout:

```bash
composer require --dev pickeringtech/harbour
```

Purpose: add Harbour as a development-only dependency. This command does not start services, change `.env`, or provision a workspace.

## 2. Prepare the project

```bash
php artisan workspace:install
```

Purpose: choose the project's local-development components and turn them into a reviewable Harbour policy shared by every checkout.

The installer uses Laravel's keyboard-driven prompts. First choose one path:

- **Auto-detect from this project** reads the existing project and presents a
  proposal for approval.
- **Choose components manually** opens focused selectors for the database,
  cache, and mail transport. The final component screen is a true multi-select:
  use Space to select several services and Enter to continue.

If the manual selection needs service processes, Harbour asks whether to use
existing shared infrastructure or generate an isolated Docker Compose stack.
It then asks whether to set up this workspace immediately. Choosing yes reserves
ports, starts managed Compose services, creates the logical database, renders
`.env`, and runs migrations. A final prompt can launch Laravel and Vite in the
same terminal immediately.

### Selected-stack preflight

Harbour validates requirements only after the user accepts auto-detection or
finishes manual selection. The proposal itself therefore remains read-only,
and checks are never based on generic defaults.

The preflight covers the selected SQL or MongoDB driver, Redis client,
Memcached, client packages for selected Laravel integrations, and the Docker
CLI plus Compose v2 plugin when Compose was chosen. Missing Composer packages
are grouped into one reviewable installation prompt and installed together.
Harbour then rechecks the selection automatically—there is no need to repeat
the menus. Redis and Valkey default to Predis so they do not require a native
PHP extension. Auto-detected PhpRedis is retained when the host can load it and
otherwise replaced by Predis; `--redis-client=phpredis` opts in explicitly.

Machine capabilities cannot safely be hidden. If the selected SQL driver is
not loaded, Harbour reports that single remaining requirement with guidance
for the detected operating system and prints a retry command containing the
completed selection. No Harbour configuration or workspace resources are
created until the recheck passes.

| Selected component | Required application capability |
| --- | --- |
| SQLite | `pdo_sqlite` |
| MySQL or MariaDB | `pdo_mysql` |
| PostgreSQL | `pdo_pgsql` |
| MongoDB | `mongodb` extension and `mongodb/laravel-mongodb` |
| Redis or Valkey | `predis/predis` by default; `redis` only for explicit PhpRedis projects |
| Memcached | `memcached` extension |
| Meilisearch or Typesense | Laravel Scout and the matching PHP client |
| MinIO or RustFS | `league/flysystem-aws-s3-v3` |
| RabbitMQ | `vladimir-yuldashev/laravel-queue-rabbitmq` |
| Selenium | `laravel/dusk` |
| Soketi | `pusher/pusher-php-server` |
| Compose provider | Docker CLI and Docker Compose v2 |

### Auto-detection

Harbour reads, in order:

1. `.env.example`, followed by `.env` as the higher-precedence Laravel settings;
2. the first recognized `compose.yaml`, `compose.yml`, `docker-compose.yaml`, or `docker-compose.yml`;
3. `herd.yml`; and
4. `composer.json` to distinguish Sail Compose configuration from a generic Compose project.

It recognizes Sail's current database, cache, mail, search, object-storage, browser, websocket, and queue services. Published `FORWARD_*` ports and Herd service ports are reused for native PHP processes. Existing credentials become template references such as `${DB_PASSWORD}`; their values are not copied into Harbour state or diagnostic metadata.

The installer displays the inferred database, cache, mail transport, additional
services, and source files. Accept the proposal to preserve that configuration,
or decline it to continue into the manual selectors.
For shared infrastructure, the proposal also lists the detected loopback ports
that must already be listening. Detection never silently opts into Compose.

If no relevant configuration exists, Harbour offers this zero-dependency setup:

- SQLite database;
- file cache and sessions;
- synchronous queues; and
- log mail.

Harbour creates `.env.harbour` and `config/harbour.php` only when missing,
appends its local state paths to `.gitignore`, and adds Composer workspace aliases
only when those names are unused. Compose mode additionally creates
`docker-compose.harbour.yml`. Existing files and scripts are never overwritten
by default. `--reconfigure` replaces only files previously marked as generated
by Harbour; `.gitignore`, Composer scripts, and unmarked files remain protected.

### What discovery does not do

Discovery itself is read-only. After the user accepts a selection, Harbour may
run Composer only with explicit approval. It does not run `sail up`, `herd
init`, or system package managers, and does not rewrite a Sail or Herd file.
Sail remains the right tool when a project wants its complete Docker
development stack; Harbour reuses its published services only when the project
chooses the lighter native-PHP, parallel-worktree model. Auto-detected policy
uses existing infrastructure. Harbour creates a Compose file only when the user
explicitly chooses Compose mode.

### Generated Docker Compose

In manual mode, choose **Docker Compose** when the selected services do not
already exist locally. Harbour writes a readable `docker-compose.harbour.yml`
for the selected services and records every host port as a concurrent Harbour
allocation. PHP, Artisan, Vite, and Node still run natively.

The final setup and launch prompts are explicit. Harbour waits for Compose
health checks before it creates the workspace database or runs migrations, then
can start Laravel and Vite as an attached session. Ctrl+C stops those processes
without tearing down the managed services.

### Agents and CI

Accept the inferred proposal without a prompt:

```bash
php artisan workspace:install --detect --no-interaction
```

Or override part of it:

```bash
php artisan workspace:install \
    --detect \
    --database=sqlite \
    --no-interaction
```

Explicit flags win for their category. If `--with` is omitted, detected optional services remain selected.

To bypass discovery entirely:

```bash
php artisan workspace:install \
    --database=postgresql \
    --cache=redis \
    --mail=mailpit \
    --with=meilisearch,minio \
    --compose \
    --start \
    --install-dependencies \
    --no-interaction
```

`-d`, `-c`, and `-m` are short forms. `--with` accepts `mysql`, `pgsql`, `mariadb`, `mongodb`, `redis`, `valkey`, `memcached`, `meilisearch`, `typesense`, `minio`, `rustfs`, `mailpit`, `rabbitmq`, `selenium`, and `soketi`.

`--compose` is shorthand for `--provider=compose` and generates a managed
Compose file. `--provider=shared` records that the service processes already
exist. `--install-dependencies` authorizes missing Composer integration
packages, and `--start` runs `workspace:setup` after scaffolding. `--launch`
starts an attached Laravel/Vite session and therefore is intended for a human
terminal rather than JSON output or CI.

Without `--detect` or explicit selections, a non-interactive install stops with
`HARBOUR_INSTALL_SELECTION_REQUIRED` before writing anything. When protected
installation files already exist, Harbour lists the exact files to delete
before deliberately choosing a different configuration; it never overwrites
them.

Use `--reconfigure` instead of deleting files when the existing protected files
were generated by Harbour. The flag does not authorize replacing unmarked
project files.

## 3. Commit the policy

Review and commit `.env.harbour`, `config/harbour.php`, `composer.json`, and
`.gitignore`. Commit `docker-compose.harbour.yml` as well when Compose was
selected. These files describe what every checkout should use; `.env`,
`.harbour.json`, and `.harbour/` remain local.

## 4. Set up a checkout

```bash
composer install
composer workspace:dev
```

`composer install` restores the untracked `vendor/` directory in a new
checkout. `workspace:dev` sets up the checkout, reserves ports, creates its
database and Laravel namespaces, preserves and renders `.env`, starts configured
resources, runs normal migrations, installs missing Node dependencies when Vite
is present, and launches Laravel plus Vite. Ctrl+C ends the attached processes.

Orchestration tools that launch their own processes should use
`composer workspace:setup` instead.

Setup is idempotent: running it again converges on the recorded workspace.
If database seeding is enabled, it runs on first setup and after `--fresh`, but
not on an already-ready workspace. Pass `--seed` for an intentional repeat.

## 5. Inspect and tear down

```bash
composer workspace:status
php artisan workspace:debug
composer workspace:teardown -- --force
```

Status is a fast operational summary. Debug shows non-secret variable provenance. Teardown removes only proven-owned resources and restores the original `.env`; `--force` skips the prompt without weakening safety checks.

Next, read [Workspaces](/workspaces/), [Environment templates](/environment-templates/), or [Vite and Reverb](/vite-and-reverb/).
