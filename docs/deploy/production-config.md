# Production configuration expectations

These values are **not** in version control (`.env` is ignored, and correctly
so). They therefore have no automatic protection against regression, which is
why they are written down here.

## Required on the live host

| Variable | Required value | Why |
|---|---|---|
| `APP_DEBUG` | `false` | Any other value makes every unhandled exception return its message and the absolute server path to the browser |
| `APP_ENV` | `production` | The conventional environment flag; other code will reasonably branch on it |

Set on 2026-08-25. Both were previously `APP_DEBUG=true` and `APP_ENV=local`.
A timestamped backup of the prior `.env` is on the host.

## What `APP_DEBUG` actually controls

`public/index.php`, in the top-level exception handler:

```php
error_log(sprintf('[church_portal] %s: %s in %s:%d', ...));   // always
http_response_code(500);
$payload = ['error' => 'Internal server error', 'kind' => 'server_error'];
if (filter_var(getenv('APP_DEBUG') ?: '', FILTER_VALIDATE_BOOLEAN)) {
    $payload['detail'] = $e->getMessage();
    $payload['where']  = $e->getFile() . ':' . $e->getLine();
}
```

`EnvLoader` calls `putenv()`, so `getenv('APP_DEBUG')` reflects the `.env`
value. With `false`, `filter_var` returns false and **`detail` and `where` are
omitted** — the client gets a generic error. The `error_log()` call is
unconditional and runs either way.

Verified on the host by loading the real `.env` through `EnvLoader` and
evaluating the same expression: the debug branch is not taken. **No exception
was induced on production to test this**, as instructed.

## Error logging — a second problem found while verifying

With `APP_DEBUG=false` the response is correctly generic. But the host had
`log_errors = Off` and no `error_log` destination, so an exception would have
left **no record anywhere**: nothing to the client, nothing to the operator.
Silent failure is worse than a logged one.

`church_portal/.user.ini` now sets:

```ini
log_errors = On
error_log = /home/u471078694/domains/crishub.com/php-error.log
display_errors = Off
display_startup_errors = Off
```

The log lives **above `public_html`**, so it is not reachable by any URL, and is
mode `600`. `.user.ini` itself returns 403 (covered by the dotfile deny rule).

**Caveat, stated plainly:** `.user.ini` is honoured by the FastCGI/LSAPI family,
which is what this host runs, and the file is syntactically correct — but the
only way to *prove* it took effect is a genuine exception, which I did not
induce. The log file was created empty and stayed empty across normal traffic,
which is consistent with there being nothing to log. **Confirm from the hosting
panel, or the next time a real error occurs.**

## Do not use `APP_ENV` as a safety control

`APP_ENV=production` is now correct, but it must never be the only thing
standing between a destructive tool and live data. This host ran with
`APP_ENV=local` for an unknown period, and one tool believed it — see
`docs/design/19-dev-data-anonymization.md`. Environment labels are
configuration, and configuration drifts.

Destructive development tooling must use independent, layered signals and fail
closed when the environment is ambiguous.
