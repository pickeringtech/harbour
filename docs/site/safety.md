# Safety

Destructive-operation safety is Harbour's first design priority.

## Persisted ownership

`.harbour.json` records the exact ports and resources acquired by setup. Teardown consumes that evidence instead of reconstructing targets from current configuration, branch names, or `.env`.

State is schema-versioned and atomically replaced. Ownership is persisted immediately after each allocation or resource preparation so a killed setup remains recoverable.

## Environment preservation

Before replacing `.env`, Harbour stores an exact private backup and its checksum. Teardown restores that original file.

If a developer edits the Harbour-rendered `.env`, ordinary teardown stops before removing resources. Forced teardown archives the modified copy before restoring the original.
Setup and `workspace:render` also stop before overwriting that edit. Their
`--force` option authorizes replacement, while leaving all resource ownership
and path checks intact.

## Database guards

Database identifiers are derived through context-specific sanitizers. PostgreSQL and MySQL/MariaDB databases carry random internal ownership markers. Teardown refuses to drop a database whose marker does not match persisted state.

SQLite paths must remain within the workspace, and Harbour removes only the file it created.

## Docker and Compose guards

Docker containers require matching Harbour workspace and resource labels before deletion. Names alone never prove ownership.

Compose receives a collision-resistant project name. Harbour validates the recorded project and Compose file before invoking teardown and does not delete externally declared resources or volumes indiscriminately.

## Hostile local input

Branch names, paths, configuration, and template values are treated as untrusted. SQL identifiers are quoted after validation, processes receive argument arrays, and managed paths are constrained to known roots.

## Production protection

Harbour is intended as a development dependency and is enabled by default only
when `APP_ENV` is `local` or `testing`. Staging, prod, and custom environments
fail closed unless they intentionally set `HARBOUR_ENABLED=true`.

`--force` suppresses interaction only. It never bypasses resource ownership, environment checksum, database, Docker, Compose, or path guards.

Report suspected vulnerabilities privately according to the [security policy](https://github.com/pickeringtech/harbour/security/policy).
