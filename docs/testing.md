# Testing Harbour

The fast suite is `composer test`. Safety-sensitive release validation also
requires PDO-backed database services, Redis, Docker, and Compose.

The release acceptance scenario creates two Laravel 13 worktrees, runs setup
concurrently, and proves distinct application/Vite/Reverb ports, databases,
Redis/cache/session and queue names, Docker labels, and Compose projects.
Teardown A must restore its original environment without changing B or shared
services. Repeat after a failing `after_setup` hook. This manual scenario
complements the automated real-Git, multi-process, PDO, Redis, Docker, and
Compose tests; it is not replaced by them.

CI environment flags are deliberately explicit so a developer's local services
are never touched accidentally:

```text
HARBOUR_DATABASE_INTEGRATION=1
HARBOUR_REDIS_INTEGRATION=1
HARBOUR_DOCKER_INTEGRATION=1
```

Mutation testing targets safety checks and uses an 85% MSI / 90% covered MSI
gate. Any surviving ownership-guard mutation should receive a regression test.
