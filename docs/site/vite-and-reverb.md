# Vite and Reverb

Harbour allocates `VITE_PORT` and `REVERB_PORT`; it configures these processes but does not supervise them.

## Vite with no project changes

Laravel's default hot marker is `public/hot`. Every Git worktree has its own `public/` directory, so the marker is already workspace-local. A normal Laravel 13 Vite project does not need custom `hotFile` code or an `AppServiceProvider` change.

Start Vite with the allocated port:

```bash
eval "$(php artisan workspace:env --format=shell)"
npm run dev -- --host 127.0.0.1 --port "$VITE_PORT" --strictPort
```

`strictPort` makes an unexpected external collision visible instead of silently choosing a different port.

### Advanced custom hot files

If a project deliberately sets `VITE_HOT_FILE`, use the same value in the Laravel Vite plugin's JavaScript `hotFile` option. Harbour automatically applies the PHP-side `Vite::useHotFile` setting through its service provider; no application provider edit is needed.

## Reverb

Generated environments set both client-facing `REVERB_PORT` and listener-facing `REVERB_SERVER_PORT` to the workspace allocation. Existing Reverb application credentials are retained through placeholders during installation.

Start Reverb normally:

```bash
eval "$(php artisan workspace:env --format=shell)"
php artisan reverb:start
```

Or pass listener values explicitly from an external process launcher:

```bash
php artisan reverb:start --host=127.0.0.1 --port="$REVERB_PORT"
```

Herd or Valet TLS remains a project/web-server concern. Harbour's zero-config path uses loopback HTTP and ports and requires no global DNS tool.
