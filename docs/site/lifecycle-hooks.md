# Lifecycle hooks

Hooks let project policy extend setup and teardown without placing orchestration logic in Artisan commands.

```php
'hooks' => [
    'before_setup' => [],
    'after_setup' => [
        [PHP_BINARY, 'artisan', 'app:prepare-local-fixtures'],
    ],
    'before_teardown' => [],
    'after_teardown' => [],
],
```

Hooks run deterministically from the current checkout and receive resolved Harbour environment values. Prefer argument arrays: they bypass the shell and preserve argument boundaries. String hooks intentionally use the system shell and should be treated as trusted project code.

A non-zero exit aborts setup or teardown with visible command context. Ownership acquired before a failed hook remains persisted, so `workspace:teardown --force` can clean it safely.

Laravel events are also dispatched:

- `WorkspaceSettingUp`
- `WorkspaceSetup`
- `WorkspaceTearingDown`
- `WorkspaceTornDown`

Harbour hooks are finite lifecycle actions. Long-running application servers, Vite, Reverb, Horizon, workers, and schedulers belong to an external process manager.
