# Herdr

**Herdr owns worktrees and panes. Harbour owns the Laravel environment inside each checkout.** Harbour can read a project `herd.yml` during installation but has no runtime dependency on Herdr.

After Herdr creates or opens a worktree:

```bash
composer install --no-interaction
composer workspace:setup
```

If the project uses Herd services, `workspace:install` detects their declared ports and configures native Laravel processes to connect through loopback. It does not run `herd init`, start a service, or rewrite `herd.yml`.

Before removing the worktree:

```bash
composer workspace:teardown -- --force
herdr worktree remove
```

Current built-in Herdr worktree commands do not provide a teardown-before-delete hook. Teams using the optional community `worktree-hooks` plugin may configure idempotent setup:

```toml
[default]
created = ["composer install --no-interaction", "composer workspace:setup"]
opened = ["test -f .harbour.json || composer workspace:setup"]
```

Do not place Harbour teardown in an after-removal hook; by then the state file needed for ownership verification is gone.
