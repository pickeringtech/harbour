# Commands

Harbour commands describe workspace operations rather than branding. Human output is concise; automation can request stable JSON.

## `workspace:install`

```bash
php artisan workspace:install
```

Purpose: interactively select the project's infrastructure and perform the one-time, non-destructive scaffolding required before Harbour can be used consistently across worktrees. Existing configuration and scripts are preserved.

For deterministic automation:

```bash
php artisan workspace:install \
    -d postgresql \
    -c redis \
    -m mailpit \
    --with=meilisearch,minio \
    --no-interaction
```

Options:

| Option | Choices | Purpose |
| --- | --- | --- |
| `-d`, `--database` | `none`, `sqlite`, `mysql`, `mariadb`, `pgsql`, `mongodb` | Select the primary datastore. `postgres` and `postgresql` alias `pgsql`. |
| `-c`, `--cache` | `none`, `file`, `database`, `redis`, `valkey`, `memcached` | Configure cache plus safe session/queue defaults. |
| `-m`, `--mail` | `none`, `log`, `mailpit` | Configure local mail delivery. |
| `--with` | Comma-separated Sail service names or `none` | Add search, object storage, RabbitMQ, Selenium, Soketi, or express the entire selection with Sail vocabulary. |
| `--json` | — | Return the selected stack and file changes using the stable JSON envelope. Explicit selections are required. |

The full Sail-compatible service list is `mysql`, `pgsql`, `mariadb`,
`mongodb`, `redis`, `valkey`, `memcached`, `meilisearch`, `typesense`, `minio`,
`rustfs`, `mailpit`, `rabbitmq`, `selenium`, and `soketi`.

Conflicting choices—such as `--database=sqlite --with=mysql` or
`--with=redis,valkey`—fail before project files are written.

## `workspace:setup`

```bash
composer workspace:setup
php artisan workspace:setup --json
```

Purpose: make the current checkout usable. Setup allocates ports, creates the workspace database, renders `.env`, starts configured Docker/Compose dependencies, and runs migrations.

`--fresh` first tears down only resources proven to belong to this workspace, then builds them again. Add `--force` to suppress the confirmation prompt.

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

Secret values are omitted unless an output mode explicitly allows `--show-secrets`. Table and debug output always redact them.

## `workspace:render`

```bash
php artisan workspace:render
```

Purpose: render `.env.harbour` into `.env` again using the existing workspace allocations. Use it after intentionally changing the template; it does not recreate the workspace.

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
