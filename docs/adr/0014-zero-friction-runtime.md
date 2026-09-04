# ADR 0014: Remediate project dependencies and provide an attached dev session

## Status

Accepted.

## Context

A developer moving from Sail may have PHP and Composer on the host while the
application's database and Redis extensions previously existed only inside its
application container. Merely listing every missing integration forced the user
to leave `workspace:install`, run several unrelated Composer commands, repeat
the component menus, and finally copy a Laravel serve command. That experience
contradicted Harbour's one-command adoption goal.

Harbour must still distinguish project dependencies from machine mutations.
Composer changes belong to the selected Laravel policy. Installing system
packages or editing global PHP configuration requires administrator authority
and differs across platforms.

## Decision

- An `auto` Redis client resolves to portable Predis. Explicit PhpRedis remains
  supported for projects that deliberately require the extension.
- After stack selection, the interactive installer offers one grouped Composer
  operation for all missing Laravel integrations. Non-interactive callers opt
  in with `--install-dependencies`.
- Requirements are inspected again in the same process. The component selection
  is never repeated.
- Remaining PHP extensions are shown as machine requirements with detected-
  platform guidance and an exact retry command containing the selection.
- After setup, interactive installation offers to launch the application.
  `workspace:dev` performs convergent setup and starts Laravel plus Vite on their
  allocated ports as one foreground session. Missing Node dependencies are
  installed through the lockfile-selected package manager. Ctrl+C stops both
  processes; infrastructure is not torn down.
- `--launch` is an attached human session and cannot be combined with JSON.
  Agents and IDEs retain `workspace:setup` and `workspace:env` for their own
  process orchestration.

## Consequences

- The normal interactive path goes from selection to a running application
  without copied commands.
- Harbour never silently invokes `sudo` or edits global PHP configuration.
- A SQL PDO driver remains a one-time native-PHP prerequisite, but the user gets
  one focused action and can retry without reconstructing their choices.
- Harbour supervises only the foreground Laravel/Vite pair. It does not broaden
  into a background daemon, queue manager, scheduler, or general process
  supervisor.
