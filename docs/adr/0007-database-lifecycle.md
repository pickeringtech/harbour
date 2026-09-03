# ADR 0007: Database lifecycle uses driver-owned records and PDO

Status: accepted

PostgreSQL, MySQL/MariaDB, and SQLite have different administrative semantics,
so Harbour uses a small lifecycle-driver contract. Drivers use PDO through
Laravel configuration rather than requiring `createdb`, `mysql`, or `psql`
binaries. Generated server identifiers use a restricted ASCII grammar and are
quoted by the driver.

Harbour persists the intended name, connection fingerprint, resource ID, and
random ownership token before asking a driver to create anything. The driver
then writes that same evidence into `_harbour_ownership`. A retry may reuse the
database only when its marker exactly matches the prepared record; every other
existing database is rejected. This closes the crash window after the marker
is written without authorizing deletion of an unmarked database. SQLite files
must resolve beneath the workspace and pre-existing files are never claimed.
