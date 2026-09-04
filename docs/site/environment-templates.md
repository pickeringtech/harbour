# Environment templates

`.env.harbour` is the only file Harbour templates. Setup resolves its placeholders and renders `.env`; arbitrary file templating is intentionally out of scope.

## Syntax

```dotenv
APP_URL=${APP_URL}
DB_DATABASE=${DB_DATABASE}
```

Only `${VARIABLE}` is supported. Shell defaults and assignments are not
interpreted. Every unresolved placeholder raises
`HARBOUR_UNRESOLVED_VARIABLE`; it never silently becomes an empty string.

## Resolution order

From lowest to highest precedence:

1. persisted non-secret workspace values;
2. template-referenced values from the pre-Harbour `.env`;
3. template-referenced process environment;
4. Harbour identity, namespace, port, URL, and database values;
5. project values under `variables`; and
6. resolver classes in their configured order.

This lets Harbour replace `APP_URL`, `DB_DATABASE`, prefixes, and allocated ports while retaining project credentials through placeholders.

## Static project variables

```php
'variables' => [
    'AWS_PROFILE' => 'development',
    'LOCAL_API_TOKEN' => [
        'value' => env('LOCAL_API_TOKEN'),
        'secret' => true,
    ],
],
```

## Custom resolvers

Classes implementing `WorkspaceVariableResolver` return `ResolvedVariable` values and are resolved through Laravel's container:

```php
'resolvers' => [App\Harbour\TenantVariableResolver::class],
```

## Secrets

Explicit `secret: true` metadata is primary. Conservative credential-name detection is a second defence. Secret values are excluded from persisted state and normal environment output and are redacted from debug output.

`workspace:env --show-secrets` is an explicit opt-in for process launchers. Table and debug views remain redacted.

## Preserving `.env`

Before rendering, Harbour stores the exact original bytes in a private mode-`0600` backup and records checksums. Ordinary teardown stops if the generated `.env` was edited. Forced teardown archives the modified copy before restoring the original; force does not discard it.

Setup and `workspace:render` enforce the same checksum before writing. Put
durable changes in `.env.harbour`; use `--force` only when the modified rendered
file should be replaced. First-time setup has no rendered checksum and proceeds
normally.
