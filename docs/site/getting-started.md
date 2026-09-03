# Getting started

Harbour has a one-time project installation and a repeated per-checkout setup. Keeping those actions separate makes every file change and resource mutation explicit.

## Requirements

- PHP 8.4 or newer
- Laravel 13 or newer
- Composer
- Linux or macOS
- The PDO driver for your configured database: `pdo_sqlite`, `pdo_pgsql`, or
  `pdo_mysql`
- Docker only if your project configures Docker or Compose resources

Install only the PDO drivers required by the database selected during
`workspace:install`. MongoDB integrations provide their own Laravel driver.

## Install once per project

Run these commands in the Laravel project's primary checkout:

```bash
composer require --dev pickeringtech/harbour
php artisan workspace:install
```

The first command adds Harbour as a development dependency. It does not start services or modify an environment.

The second command asks which database, cache, mail transport, and optional
shared services the project uses, then prepares the project by:

- creating `.env.harbour` only when it is missing;
- creating `config/harbour.php` only when it is missing;
- adding Harbour's local state paths to `.gitignore`; and
- adding the `workspace:setup`, `workspace:status`, and `workspace:teardown` Composer aliases when those names are unused.

Existing files and Composer scripts are never replaced. Review and commit `.env.harbour`, `config/harbour.php`, `composer.json`, and `.gitignore` so every worktree receives the same project policy.

### Non-interactive installation

Agents and CI should provide explicit choices:

```bash
php artisan workspace:install \
    --database=postgresql \
    --cache=redis \
    --mail=mailpit \
    --with=meilisearch,minio \
    --no-interaction
```

The category flags also have `-d`, `-c`, and `-m` shortcuts. PostgreSQL accepts
`pgsql`, `postgres`, or `postgresql`. `--with` accepts Sail-compatible service
names or `none`.

If a non-interactive process supplies no choices, installation stops with
`INSTALL_SELECTION_REQUIRED` before writing files. This prevents automation
from silently adopting SQLite or any other datastore.

### Shared means shared

Install selections configure existing shared services on loopback addresses.
Harbour creates the workspace database or namespace, but it does not install or
supervise the database, Redis, Mailpit, search engine, or other daemon. Use the
documented Docker or Compose configuration when a dependency genuinely needs a
workspace-owned container.

## Set up each checkout

```bash
composer install
composer workspace:setup
```

`composer install` installs the dependencies declared by the project. It is needed in a newly created worktree because `vendor/` is not committed.

`composer workspace:setup` asks Harbour to identify the current checkout, reserve its ports, create its database, resolve workspace namespaces, render `.env`, start configured optional resources, and run normal migrations.

The command is idempotent. Running it again converges on the same workspace and does not recreate owned resources unnecessarily.

Harbour preserves an existing `.env` before rendering. If no `APP_KEY` is available in a new worktree, it generates a workspace-local key and treats it as a secret.

## See what was created

```bash
composer workspace:status
php artisan workspace:debug
```

Status is the concise operational view. Debug explains where each non-secret variable came from; secret values remain redacted.

## Tear down before deleting the checkout

```bash
composer workspace:teardown -- --force
```

Teardown removes only resources recorded as Harbour-owned, releases port reservations, and restores the original `.env`. `--force` removes the confirmation prompt for automation. It does not weaken database, Docker, Compose, or path ownership checks.

Run teardown while the checkout still exists. Git, Orca, Herdr, or another orchestration tool may remove the worktree afterwards.

## Next steps

- Read [Commands](/commands/) for every CLI operation and output format.
- Read [Isolation](/isolation/) to connect Laravel cache, sessions, queues, Vite, and Reverb.
- Read [Configuration](/configuration/) before adding Docker services or lifecycle hooks.
