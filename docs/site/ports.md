# Ports

Harbour allocates application, Vite, and Reverb ports by default and supports arbitrary named requirements.

```php
'ports' => [
    'strategy' => DefaultPortAllocationStrategy::class,
    'allocations' => [
        'APP_PORT' => ['range' => [8000, 8999]],
        'VITE_PORT' => ['range' => [9000, 9999]],
        'REVERB_PORT' => ['range' => [10000, 10999]],
        'METRICS_PORT' => ['range' => [11000, 11999]],
    ],
],
```

Names use an environment-variable grammar. Ranges must use unprivileged ports and valid loopback hosts.

## Concurrency model

Allocation happens under a machine-wide `flock`. Harbour records the reservation atomically in its XDG state registry and verifies the candidate with a real loopback socket bind before returning it. Two Harbour processes therefore cannot both win the same check-and-reserve race.

Reservations persist across process crashes and are associated with a workspace ID and path. Reconciliation can reclaim an entry whose checkout no longer exists, but never destroys an external resource merely because an entry looks stale.

Harbour is not a daemon and does not hold every socket open. An unrelated non-Harbour process could claim a reserved port later, so long-running process launchers should enable strict-port behaviour and fail clearly.

Use a custom `PortAllocationStrategy` when a team needs centrally assigned ranges or another local policy. See [Custom strategies](/custom-strategies/).
