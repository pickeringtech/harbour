<div class="hero">
<div class="eyebrow">Laravel workspaces, without the fleet</div>

# Every checkout, ready to work.

<p class="lead">Harbour gives each Laravel clone or Git worktree its own ports, database, Redis namespaces, sessions, queues, Vite state, and optional Docker resources—while shared infrastructure stays shared.</p>

<div class="actions"><a class="button" href="/getting-started/">Install Harbour</a><a class="button secondary" href="/architecture/">How it works</a></div>
</div>

![Worktrees using isolated namespaces on shared infrastructure](/images/readme/shared-infrastructure.svg)

<div class="cards">
<div class="card"><h3>Lightweight</h3><p>Run PHP and Node natively. Reuse PostgreSQL, Redis, Mailpit, and other infrastructure.</p></div>
<div class="card"><h3>Isolated</h3><p>Databases, ports, queues, cache, sessions, and Vite hot files stay workspace-specific.</p></div>
<div class="card"><h3>Safe teardown</h3><p>Harbour removes only resources backed by persisted ownership evidence.</p></div>
</div>

## The everyday workflow

Project maintainers install Harbour once:

```bash
composer require --dev pickeringtech/harbour
php artisan workspace:install
```

The install command detects Sail, Compose, Herd, and Laravel environment choices,
shows one proposal, and generates configuration only after approval. With no
existing infrastructure it offers a zero-dependency SQLite/file/log setup.

After those generated project files are reviewed and committed, every developer or agent uses:

```bash
composer install
composer workspace:setup
```

`composer install` installs the checkout's PHP dependencies. `composer workspace:setup` creates and configures only that checkout's isolated local environment.

When the checkout is about to be removed:

```bash
composer workspace:teardown -- --force
```

This removes Harbour-owned resources and restores the `.env` that existed before setup. `--force` skips the prompt; it never bypasses ownership checks.

## Clear ownership boundaries

Harbour does not create worktrees, manage branches or agents, install PHP or Node, or supervise long-running processes. Git, Orca, Herdr, and humans own the checkout. Harbour owns the Laravel environment inside it.

## Learn by concern

- [Workspaces](/workspaces/) explains identity, concurrency, setup, and teardown.
- [Databases](/databases/) and [Laravel state](/laravel-state/) cover isolation.
- [Vite and Reverb](/vite-and-reverb/) removes the usual multi-worktree port and hot-file friction.
- [Docker](/docker/) and [Docker Compose](/docker-compose/) remain optional resource providers.
- [Safety](/safety/) explains the evidence required before Harbour deletes anything.
