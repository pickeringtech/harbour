# Testing Harbour

The fast suite is `composer test`. Safety-sensitive release validation also
requires PDO-backed database services, Redis, Docker, and Compose.

`composer coverage` generates `build/coverage.xml` and fails unless line
coverage across `src/` is at least 95%. CI runs this command with every real
integration service enabled, so skipped database, Redis, Docker, or Compose
tests cannot make the percentage look healthier than the release suite really
is.

The release acceptance scenario is automated by `composer acceptance`. It
creates a real Laravel 13 project and two real Git worktrees, installs and runs
setup concurrently, and proves distinct application/Vite/Reverb ports,
databases, Redis/cache/session and queue names, cache locks, Docker containers,
and Compose projects. It launches real Vite and Reverb processes in both
worktrees, verifies separate checkout-local hot files and listeners, tears down
A while proving B remains operational, then repeats cleanup after a failing
`after_setup` hook. The fixture also runs `workspace:install --detect` against
a real Laravel application before committing its policy.

The CI acceptance job uses PostgreSQL, phpredis, Redis, Docker, and Compose. A
local run needs those services and extensions, or may deliberately substitute
SQLite and Predis while retaining the same full-worktree lifecycle:

```bash
HARBOUR_ACCEPTANCE_DATABASE=sqlite \
REDIS_CLIENT=predis \
HARBOUR_ACCEPTANCE_DOCKER=1 \
composer acceptance
```

The acceptance harness creates everything below a validated temporary
directory and cleans it on exit. It never uses a developer application or
database.

Documentation diagrams are Mermaid source in `README.template.md` and
`docs/architecture.template.md`. `npm run readme:render` generates the
published Markdown and committed SVGs; `npm run readme:check` is the CI
freshness gate. SVGs carry a source/config/renderer fingerprint because browser
font metrics are not byte-identical across platforms; CI renders every Mermaid
block to validate it, then verifies that fingerprint and accessibility metadata
on the committed asset. Mermaid runs Chromium without its sandbox only inside
this trusted, ephemeral documentation-rendering process because hosted Ubuntu
runners block Chromium's user-namespace sandbox.

CI environment flags are deliberately explicit so a developer's local services
are never touched accidentally:

```text
HARBOUR_DATABASE_INTEGRATION=1
HARBOUR_REDIS_INTEGRATION=1
HARBOUR_DOCKER_INTEGRATION=1
```

Mutation testing targets safety checks and uses an 85% MSI / 90% covered MSI
gate. Any surviving ownership-guard mutation should receive a regression test.
Run it with `composer mutate`.

Eris property tests fuzz context-specific identifiers with arbitrary strings,
including Unicode and hostile shell/SQL-like input. They run as part of the
normal PHPUnit suite and have a dedicated `composer fuzz` CI gate. Add focused
generators whenever a new transformation accepts branch names, paths, service
names, or other untrusted local input.
