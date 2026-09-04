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

Process failures include a redacted stderr tail, limited to 4 KiB, in both human
and JSON output. Machine failures use a versioned JSON envelope and namespaced
codes such as `HARBOUR_PORT_ALLOCATION_FAILED`,
`HARBOUR_UNRESOLVED_VARIABLE`, `HARBOUR_ENVIRONMENT_MODIFIED`, or
`HARBOUR_DATABASE_NOT_OWNED`. JSON consumers should branch on `error.code`.

If setup fails halfway through, inspect status and run:

```bash
composer workspace:teardown -- --force
```

Incremental state lets teardown remove only what the failed attempt actually acquired.

Do not delete `.harbour.json` as a substitute for teardown. The state contains
the ownership tokens Harbour needs to clean resources safely. If the file is
accidentally lost but a database remains, a later setup can recover it only
when its internal marker proves it belongs to the exact same workspace.
Otherwise Harbour fails closed and reports the underlying ownership error.
