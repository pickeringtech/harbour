# ADR 0011: Enablement is allowlisted and creation intent is explicit

Status: accepted

Harbour can drop owned databases and stop owned containers, so absence of the
literal `production` environment name is not sufficient authorization.
Harbour is enabled by default only for Laravel's `local` and `testing`
environments. Staging, prod, custom review environments, and every other name
must opt in with `HARBOUR_ENABLED=true`.

Retries distinguish intent from completed ownership. Database and direct
Docker records persist `creation_pending=true` before the external create and
replace it with `false` immediately afterward. Only a pending record may retry
creation. A completed or legacy record whose external ownership evidence is
missing requires `workspace:teardown --force` followed by a fresh setup;
`--force` never bypasses the ownership checks.
