# Getting started

Harbour has one project-level installation and one repeatable checkout-level setup. The installer makes the choices visible; setup makes each worktree usable.

## Requirements

- PHP 8.4 or newer
- Laravel 13 or newer
- Composer
- Linux or macOS
- the PDO extension for PostgreSQL, MySQL/MariaDB, or SQLite when selected
- Docker only for explicitly configured Docker or Compose resources

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
`.env`, and runs migrations before the installer returns.

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

If no relevant configuration exists, Harbour offers this zero-dependency setup:

- SQLite database;
- file cache and sessions;
- synchronous queues; and
- log mail.

Harbour creates `.env.harbour` and `config/harbour.php` only when missing,
appends its local state paths to `.gitignore`, and adds Composer workspace aliases
only when those names are unused. Compose mode additionally creates
`docker-compose.harbour.yml`. Existing files and scripts are never overwritten.

### What discovery does not do

Discovery is read-only. Harbour does not run `sail up`, `herd init`, Docker,
package managers, or system-service installers. It does not rewrite a Sail or
Herd file. Sail remains the right tool when a project wants its complete Docker
development stack; Harbour reuses its published services only when the project
chooses the lighter native-PHP, parallel-worktree model. Auto-detected policy
uses existing infrastructure. Harbour creates a Compose file only when the user
explicitly chooses Compose mode.

### Generated Docker Compose

In manual mode, choose **Docker Compose** when the selected services do not
already exist locally. Harbour writes a readable `docker-compose.harbour.yml`
for the selected services and records every host port as a concurrent Harbour
allocation. PHP, Artisan, Vite, and Node still run natively.

The final start prompt is explicit. Answer yes to run the newly installed policy
immediately, or no to review and commit it first. Harbour waits for Compose
health checks before it creates the workspace database or runs migrations.

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
    --no-interaction
```

`-d`, `-c`, and `-m` are short forms. `--with` accepts `mysql`, `pgsql`, `mariadb`, `mongodb`, `redis`, `valkey`, `memcached`, `meilisearch`, `typesense`, `minio`, `rustfs`, `mailpit`, `rabbitmq`, `selenium`, and `soketi`.

`--compose` is shorthand for `--provider=compose` and generates a managed
Compose file. `--provider=shared` records that the service processes already
exist. `--start` runs `workspace:setup` after scaffolding. All choices are thus
available without prompts for agents and CI.

Without `--detect` or explicit selections, a non-interactive install stops with `INSTALL_SELECTION_REQUIRED` before writing anything.

## 3. Commit the policy

Review and commit `.env.harbour`, `config/harbour.php`, `composer.json`, and
`.gitignore`. Commit `docker-compose.harbour.yml` as well when Compose was
selected. These files describe what every checkout should use; `.env`,
`.harbour.json`, and `.harbour/` remain local.

## 4. Set up a checkout

```bash
composer install
composer workspace:setup
```

`composer install` restores the untracked `vendor/` directory in a new checkout. `workspace:setup` identifies that checkout, reserves ports, creates its database and Laravel namespaces, preserves and renders `.env`, starts explicitly configured optional resources, and runs normal migrations.

Setup is idempotent: running it again converges on the recorded workspace.

## 5. Inspect and tear down

```bash
composer workspace:status
php artisan workspace:debug
composer workspace:teardown -- --force
```

Status is a fast operational summary. Debug shows non-secret variable provenance. Teardown removes only proven-owned resources and restores the original `.env`; `--force` skips the prompt without weakening safety checks.

Next, read [Workspaces](/workspaces/), [Environment templates](/environment-templates/), or [Vite and Reverb](/vite-and-reverb/).
