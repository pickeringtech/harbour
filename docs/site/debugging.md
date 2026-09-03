# Debugging

Start with persisted status:

```bash
composer workspace:status
php artisan workspace:status --json
```

Status reads `.harbour.json`; it does not scan repositories or enumerate every Docker resource on the machine.

Inspect variable provenance:

```bash
php artisan workspace:debug
php artisan workspace:debug DB_DATABASE
php artisan workspace:debug --json
```

Debug output shows source, persistence, and secret classification. Sensitive values always appear as `[REDACTED]`.

Inspect values for an external launcher:

```bash
php artisan workspace:env --format=json
php artisan workspace:env --format=dotenv
php artisan workspace:env --format=shell
```

Shell output uses robust single-quote escaping and is intended for:

```bash
eval "$(php artisan workspace:env --format=shell)"
```

Secrets are omitted unless `--show-secrets` is explicitly requested in a supported environment output. Avoid logging that output.

Laravel verbosity flags expose lifecycle stages and external commands without printing secret values. Machine failures use a versioned JSON envelope and stable error code such as `PORT_ALLOCATION_FAILED`, `UNRESOLVED_VARIABLE`, `ENVIRONMENT_MODIFIED`, or `DATABASE_NOT_OWNED`.

If setup fails halfway through, inspect status and run:

```bash
composer workspace:teardown -- --force
```

Incremental state lets teardown remove only what the failed attempt actually acquired.
