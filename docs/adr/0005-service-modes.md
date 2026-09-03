# ADR 0005: Shared, Docker, and Compose are distinct service modes

Status: accepted

Shared services are preferred because databases and prefixes offer cheap,
high-density isolation. Docker mode is for one isolated dependency container.
Compose mode is for a project-defined dependency graph. PHP and Node remain
native by default in all modes.

Keeping the modes explicit makes ownership and teardown reviewable. Docker
resources require Harbour labels. Compose receives a unique project name and
does not remove declared external resources or volumes by default. Harbour is
therefore a resource provider, not another application container platform.
