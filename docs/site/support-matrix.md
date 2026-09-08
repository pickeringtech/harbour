# Support and test evidence

Harbour separates a selectable integration from a fully proven integration.
The installer can render more services than the CI suite currently launches.
This page makes that distinction explicit so adoption decisions are based on
evidence rather than a broad compatibility claim.

## Evidence levels

| Level | Meaning |
| --- | --- |
| **End-to-end** | CI exercises Harbour setup, real use, and safe teardown against a Laravel workspace or backing service. |
| **Real runtime** | CI starts, connects to, health-checks, and removes the real service, but has not completed every Laravel integration action. |
| **Generation-only** | CI covers selection, validation, environment output, dependency requirements, and Compose syntax. It does not launch the real service. |
| **Experimental** | The configuration contract exists, but real-service evidence is intentionally incomplete or Harbour provides connection-only support. |

## Databases

| Selection | Evidence | What CI proves |
| --- | --- | --- |
| None | End-to-end | A workspace can run without a Harbour-managed database. |
| SQLite | End-to-end | Fresh install, migrations, file ownership, repeated setup, and teardown. |
| PostgreSQL | End-to-end | Real database lifecycle plus simultaneous worktrees with independent databases. |
| MySQL | End-to-end | Real creation, ownership-marker checks, idempotency, collision protection, and teardown. |
| MariaDB | End-to-end | Generated Compose startup, readiness, owned database creation, repeated setup, and teardown. |
| MongoDB | Experimental | Connection-only environment and valid Compose output. Harbour never claims ownership of MongoDB databases. |

## Cache and shared state

| Selection | Evidence | What CI proves |
| --- | --- | --- |
| None / array | End-to-end | Laravel lifecycle without an external cache service. |
| File | End-to-end | File-backed local state in fresh and repeated workspace lifecycle. |
| Database | End-to-end | Laravel's default database-backed state in a fresh Laravel application. |
| Redis | End-to-end | Real key isolation, Compose readiness, stable allocations, concurrent worktrees, and teardown. |
| Valkey | Generation-only | Environment, Predis/PhpRedis resolution, preflight, and valid Compose output. |
| Memcached | Generation-only | Environment, client requirements, preflight, and valid Compose output. |

## Mail and additional services

| Selection | Evidence | What CI proves |
| --- | --- | --- |
| None / array mail | End-to-end | Laravel lifecycle without an SMTP service. |
| Log mail | End-to-end | Fresh Laravel installation and acceptance setup. |
| Mailpit | Real runtime | Real Compose startup, health, SMTP/dashboard ports, and teardown. |
| Meilisearch | Generation-only | Environment, Scout/client requirements, and valid Compose output. |
| Typesense | Generation-only | Environment, Scout/client requirements, and valid Compose output. |
| MinIO | Generation-only | Environment, filesystem dependency requirements, and valid Compose output. |
| RustFS | Generation-only | Environment, filesystem dependency requirements, and valid Compose output. |
| RabbitMQ | Generation-only | Environment, queue dependency requirements, and valid Compose output. |
| Selenium | Generation-only | Environment, Dusk dependency requirements, and valid Compose output. |
| Soketi | Generation-only | Environment, Pusher dependency requirements, and valid Compose output. |

Generation-only does not mean “known broken.” It means Harbour will not claim
runtime proof that CI does not yet possess. Each service should move upward only
when an automated test starts the real image, connects through Laravel where
applicable, demonstrates isolated use, and proves teardown.

## Worktree lifecycle integrations

| Selection | Evidence | What CI proves |
| --- | --- | --- |
| Orca `1.4.x` | Experimental, disabled | Pinned Orca `1.4.197` loads generated YAML and runs setup/archive, but deletes the checkout after archive failure. The adapter fails closed pending [stablyai/orca#19334](https://github.com/stablyai/orca/issues/19334). |
| Worktrunk `0.76.x` | End-to-end | The real pinned `wt` validator accepts generated TOML. Real Worktrunk creation orders Composer before setup, two worktrees retain independent ownership state, teardown failure blocks deletion, successful removal leaves the sibling intact, and merge invokes blocking pre-remove. |

Worktrunk is the reference first-class headless adapter. Orca and Herdr are not
first-class until their lifecycle contracts satisfy the same automated support
gate. Orca CLI exposes `--run-hooks`, but that flag is not considered a blocking
archive capability while hook failure still permits deletion.

## Providers and platforms

Shared infrastructure and generated Docker Compose are both exercised
end-to-end. Standalone Docker resource ownership also runs against a real Docker
daemon.

Linux is the fully tested platform. macOS remains supported, but its current
automated evidence is limited to portable design and generation behaviour.
Windows is best-effort and experimental for the first release series.

The machine-readable companion to this page is
[`docs/support-matrix.json`](https://github.com/pickeringtech/harbour/blob/main/docs/support-matrix.json).
A PHPUnit contract test ensures every installer selection is classified and
that every classification uses a documented evidence level.
