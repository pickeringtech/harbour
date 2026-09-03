# Databases

Harbour supports PostgreSQL, MySQL, MariaDB, and SQLite lifecycle management. MongoDB may be selected for environment generation, but its external Laravel driver owns database lifecycle.

```php
'database' => [
    'enabled' => true,
    'connection' => 'pgsql',
    'sqlite_path' => 'database/harbour.sqlite',
    'migrate' => true,
    'seed' => false,
],
```

Setup derives a context-safe workspace database name, connects through Laravel/PDO configuration, creates the database, writes a random ownership marker, persists the exact resource evidence, applies the connection to Laravel, and runs normal migrations. Seeding is opt-in.

Repeated setup uses normal migrations; it never runs `migrate:fresh` implicitly.

## PostgreSQL and MySQL/MariaDB

Harbour uses PDO instead of requiring `createdb`, `dropdb`, `psql`, or `mysql` executables. Identifiers are validated and quoted for the driver.

Before deletion, state, driver, server fingerprint, database name, workspace ID, and the marker stored inside the database must agree. A current `.env` database name is not ownership evidence.

## SQLite

The default file is `database/harbour.sqlite`. Its path must stay inside the checkout and may not traverse symlinks. Harbour creates and removes only a file recorded as Harbour-owned; a pre-existing SQLite file is never adopted silently.

## Shared services

A single PostgreSQL or MySQL daemon can host many Harbour databases. Sail, Herd, a native service manager, or a long-lived Docker container may provide that daemon. Harbour owns the logical workspace database, not the shared server.
