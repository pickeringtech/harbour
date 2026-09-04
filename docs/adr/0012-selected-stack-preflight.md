# ADR 0012: Preflight the final installation selection

## Status

Accepted.

## Context

Harbour cannot know which database extensions, Laravel clients, or external
tools a project needs until the user accepts a detected proposal or completes
manual selection. Checking defaults earlier would reject valid projects and
checking during setup can leave a partially created workspace.

This is especially important for projects moving from Sail. Their host PHP may
be sufficient to run Composer and Artisan while database or cache extensions
previously existed only inside Sail's application container.

## Decision

`workspace:install` resolves the final `InstallationSelection`, then runs one
read-only preflight before invoking `ProjectInstaller` or starting the
workspace. Requirements are conditional on that selection:

- the selected database determines its PHP driver;
- Redis and Valkey use the Laravel-configured PhpRedis or Predis client;
- Memcached and MongoDB require their runtime extensions;
- selected Laravel integrations require their corresponding Composer clients;
- Compose mode requires the Docker CLI and Compose v2 plugin.

All missing requirements are collected into one actionable error. Each entry
names the capability, explains why it is needed, and provides a resolution. The
machine-readable error code is `HARBOUR_INSTALL_REQUIREMENTS_MISSING`.

There is no force flag that bypasses this check. The installer must not produce
a policy it already knows the current Laravel runtime cannot execute.

## Consequences

- Requirements reflect the user's actual choice rather than Harbour defaults.
- A failed install leaves project files and infrastructure untouched.
- Sail users receive an immediate explanation of host-runtime differences.
- Adding a supported installer component includes defining and testing its
  runtime requirements.
