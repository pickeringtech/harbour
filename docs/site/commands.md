# Commands

Harbour commands describe workspace operations rather than branding. Human output is concise; automation can request stable JSON.

## `workspace:install`

```bash
php artisan workspace:install
```

Purpose: interactively select the project's infrastructure and perform the one-time, non-destructive scaffolding required before Harbour can be used consistently across worktrees. Existing configuration and scripts are preserved.

With no options, a TUI first asks whether to auto-detect the existing project or
choose components manually. Manual mode uses single-select controls for the
database, cache, and mail transport, and a multi-select control for additional
services. When service processes are needed it can generate Docker Compose, and
it can set up the first workspace immediately.

Once the final stack is known, the installer validates its PHP extensions,
Laravel client packages, and—when selected—Docker Compose tooling. It can install
the selected Composer integrations in one reviewed operation and then rechecks
the stack automatically. Portable Predis is the default for Redis and Valkey.
Missing machine capabilities are reported separately with platform guidance and
an exact retry command under `HARBOUR_INSTALL_REQUIREMENTS_MISSING`.

Use `--detect` to accept the discovered Sail, Compose, Herd, and Laravel
configuration without interaction.

For deterministic automation:

```bash
php artisan workspace:install \
    --detect \
    -d postgresql \
    -c redis \
    -m mailpit \
    --with=meilisearch,minio \
    --compose \
    --start \
    --install-dependencies \
    --no-interaction
```

Options:

| Option | Choices | Purpose |
| --- | --- | --- |
| `--detect` | — | Infer choices and host ports from Sail, Compose, Herd, `.env`, and `.env.example`. Explicit category flags override the inferred category. |
| `-d`, `--database` | `none`, `sqlite`, `mysql`, `mariadb`, `pgsql`, `mongodb` | Select the primary datastore. `postgres` and `postgresql` alias `pgsql`. |
| `-c`, `--cache` | `none`, `file`, `database`, `redis`, `valkey`, `memcached` | Configure cache plus safe session/queue defaults. |
| `-m`, `--mail` | `none`, `log`, `mailpit` | Configure local mail delivery. |
| `--with` | Comma-separated Sail service names or `none` | Add search, object storage, RabbitMQ, Selenium, Soketi, or express the entire selection with Sail vocabulary. |
| `--provider` | `shared`, `compose` | Use existing host/shared services or generate a workspace-specific Compose stack. |
| `--redis-client` | `auto`, `predis`, `phpredis` | Use portable Predis by default or preserve an explicit PhpRedis project. |
| `--compose` | — | Shorthand for `--provider=compose`. Generates `docker-compose.harbour.yml`. |
| `--start` | — | Run `workspace:setup` after files are installed and wait for managed services to become ready. |
| `--launch` | — | Implies `--start`, then launches Laravel and Vite as an attached session. Incompatible with `--json`. |
| `--install-dependencies` | — | Allow non-interactive installation of selected Composer integration packages. Interactive runs ask first. |
| `--reconfigure` | — | Replace only files carrying Harbour's generated-file marker. Unmarked files, `.gitignore`, and Composer scripts remain protected. |
| `--json` | — | Return the selected stack, discovery sources, and file changes using the stable JSON envelope. Use `--detect` or explicit selections. |

Interactive auto-detection and manual configuration both ask whether to set up
the first workspace. `--detect` remains non-interactive, and `--start` opts in
explicitly. A successful `--json --start` response includes the resulting
top-level `workspace` payload. If protected files already exist, the result
lists the exact paths that must be removed before reconfiguration.

The full Sail-compatible service list is `mysql`, `pgsql`, `mariadb`,
`mongodb`, `redis`, `valkey`, `memcached`, `meilisearch`, `typesense`, `minio`,
`rustfs`, `mailpit`, `rabbitmq`, `selenium`, and `soketi`.

Conflicting choices—such as `--database=sqlite --with=mysql` or
`--with=redis,valkey`—fail before project files are written.

`--compose` and `--provider=shared` conflict deliberately. Compose mode also
requires at least one service-backed component; a SQLite/file/log selection has
nothing to containerize. Starting is a normal Harbour setup operation, so all
port, ownership, state, and teardown guarantees still apply.

## `workspace:dev`

```bash
composer workspace:dev
```

Purpose: make a checkout usable and launch it in one step. The command performs
idempotent workspace setup, installs Node dependencies when a Laravel Vite
project needs them, and starts Laravel plus Vite on their allocated ports.
Both processes remain attached to the terminal and stop with Ctrl+C. Managed
databases and services remain ready for the next run.

Use `--no-vite` for an API-only Laravel session. Harbour deliberately does not
start Reverb, Horizon, queue workers, or the scheduler; those have project-
specific operational semantics.

## `workspace:setup`

```bash
composer workspace:setup
php artisan workspace:setup --json
```

Purpose: make the current checkout usable. Setup allocates ports, creates the workspace database, renders `.env`, starts configured Docker/Compose dependencies, and runs migrations.

`--fresh` first tears down only resources proven to belong to this workspace,
then builds them again. Add `--force` to suppress the confirmation prompt.
Non-interactive `--fresh` and teardown fail closed without `--force`; an
interactive “no” is reported as an abort without claiming work completed.
Configured seeders run on first setup and after `--fresh`. Use `--seed` to seed
an already-ready workspace intentionally. If the rendered `.env` was edited,
setup refuses to replace it unless `--force` is supplied.

## `workspace:status`

```bash
composer workspace:status
php artisan workspace:status --json
```

Purpose: inspect persisted workspace state quickly without scanning the machine or discovering unrelated Docker resources.

## `workspace:env`

```bash
php artisan workspace:env
php artisan workspace:env --format=json
php artisan workspace:env --format=dotenv
php artisan workspace:env --format=shell
```

Purpose: expose resolved non-secret workspace variables to humans or external process launchers.

For example:

```bash
eval "$(php artisan workspace:env --format=shell)"
php artisan serve --port="$APP_PORT"
```

This export remains available to external orchestrators. A developer who just
wants to run the application can use `composer workspace:dev` instead.

Secret values are omitted unless an output mode explicitly allows `--show-secrets`. Table and debug output always redact them.

## `workspace:render`

```bash
php artisan workspace:render
```

Purpose: render `.env.harbour` into `.env` again using the existing workspace allocations. Use it after intentionally changing the template; it does not recreate the workspace. A checksum mismatch protects hand edits; move durable values to `.env.harbour` or pass `--force` to replace the modified render.

## `workspace:debug`

```bash
php artisan workspace:debug
php artisan workspace:debug APP_PORT
php artisan workspace:debug --json
```

Purpose: explain variable values, provenance, persistence, and secret classification. This is the first command to use when a rendered setting is surprising.

## `workspace:teardown`

```bash
composer workspace:teardown -- --force
```

Purpose: remove this checkout's Harbour-owned resources, release its ports, restore its previous `.env`, and delete its state file.

The Composer `--` forwards `--force` to Artisan. Force means “do not prompt,” never “ignore safety.”
