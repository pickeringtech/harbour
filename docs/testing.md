# Testing Harbour

The fast suite is `composer test`. Safety-sensitive release validation also
requires PDO-backed database services, Redis, Docker, and Compose.

`composer coverage` generates `build/coverage.xml` and fails unless line
coverage across `src/` is at least 95%. CI runs this command with every real
integration service enabled, so skipped database, Redis, Docker, or Compose
tests cannot make the percentage look healthier than the release suite really
is.

The release acceptance scenario creates two Laravel 13 worktrees, runs setup
concurrently, and proves distinct application/Vite/Reverb ports, databases,
Redis/cache/session and queue names, Docker labels, and Compose projects.
Teardown A must restore its original environment without changing B or shared
services. Repeat after a failing `after_setup` hook. This manual scenario
complements the automated real-Git, multi-process, PDO, Redis, Docker, and
Compose tests; it is not replaced by them.

Documentation diagrams are Mermaid source in `README.template.md` and
`docs/architecture.template.md`. `npm run readme:render` generates the
published Markdown and committed SVGs; `npm run readme:check` is the CI
freshness gate.

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
