# ephpm/octane-driver

Run a **Laravel** application under **ePHPm persistent worker mode** via a
[Laravel Octane](https://laravel.com/docs/octane) server driver.

In worker mode ePHPm keeps your Laravel app bootstrapped in memory and dispatches
each HTTP request to a long-lived PHP worker, avoiding the per-request framework
bootstrap cost — the same idea as Octane's Swoole/RoadRunner/FrankenPHP drivers,
but backed by ePHPm's single-binary worker runtime.

This package implements Octane's engine-neutral
`Laravel\Octane\Contracts\Client` and drives Octane's own
`Laravel\Octane\Worker` loop, translating each ePHPm request `Envelope` into an
`Illuminate\Http\Request` and each Octane response back into ePHPm's
`send_response()` primitive.

## Install

```bash
composer require ephpm/octane-driver
```

You also need Laravel Octane installed and published in your app:

```bash
composer require laravel/octane
php artisan octane:install
```

> **Note (pre-Packagist):** until `ephpm/worker` is published on Packagist, this
> package resolves that dependency from its GitHub repository (tag `v0.1.0`) via
> the Composer `repositories` VCS entry declared in `composer.json`. Remove that
> block once `ephpm/worker` is on Packagist.

## Wiring it into ePHPm

Point ePHPm at the worker entrypoint, switch on worker mode, and set the document
root to Laravel's `public/` directory in `ephpm.toml`:

```toml
[php]
mode          = "worker"
worker_script = "vendor/bin/ephpm-octane-worker"
document_root = "public"          # Laravel's public/ directory
```

Then start the server:

```bash
ephpm serve
```

`bin/ephpm-octane-worker` finds your project's `vendor/autoload.php` and your
Laravel `bootstrap/app.php` (searching upward from the working directory, or from
`EPHPM_APP_BASE` / the first CLI argument), boots an Octane worker with our
`EphpmClient`, and runs the request loop until ePHPm signals shutdown.

### Locating the app base

If autodetection picks the wrong directory (e.g. nested projects), pin it:

```toml
[php]
mode          = "worker"
worker_script = "vendor/bin/ephpm-octane-worker"
worker_args   = ["/srv/app"]      # dir containing bootstrap/app.php
# or set the EPHPM_APP_BASE environment variable
```

## Why `ephpm serve`, not `octane:start --server=ephpm`

We intentionally run through `ephpm serve` (which supervises the workers) rather
than `php artisan octane:start --server=ephpm`. The artisan command expects a
driver to implement a much larger surface — a `ServerProcessInspector` and a
`ServerStateFile` (PID files, worker-count reload, process supervision) — because
Octane's CLI is what starts and babysits the server process. Under ePHPm the
**runtime** owns process supervision, so that contract would be redundant.

A first-class `--server=ephpm` binding (registering an Octane server command +
inspector + state file) is a **documented follow-up**, not part of this release.

## What it maps

**Request** (`EphpmClient::marshalRequest`): reads the `Ephpm\Worker\Envelope`
out of Octane's `RequestContext->data['envelope']`, builds a
`Symfony\Component\HttpFoundation\Request` from the envelope's
server/query/body/cookies/files/raw-body, then lifts it with
`Illuminate\Http\Request::createFromBase()`. Mirroring Octane's Swoole converter,
a form-urlencoded body on `PUT`/`PATCH`/`DELETE` is parsed out of the raw body
and injected into the request parameter bag (PHP only does this automatically for
`POST`).

**Response** (`EphpmClient::respond`): prepends Octane's captured `$outputBuffer`
(anything echoed outside the `Response`) to the body, flattens
`allPreserveCaseWithoutCookies()` headers to `['Name' => 'v1, v2']`, appends a
`Set-Cookie` line per queued cookie, then calls `send_response()`.

**Error** (`EphpmClient::error`): emits a generic `500 Internal Server Error`
without leaking the exception (your app's logging surfaces the detail).

## RequestContext note

Octane owns its own `Laravel\Octane\RequestContext` class (a loosely-typed bag
with `public array $data`). We cannot replace it, so we carry the ePHPm envelope
inside it at `data['envelope']`. This package also ships a thin, strongly-typed
`Ephpm\Octane\RequestContext` value object (with `fromOctane()` / `toOctane()`)
used by the worker loop and tests — see its class docs for the deviation
rationale.

## Streaming caveat

The request body is buffered as a string (`Envelope::rawBody()`), and the
response body is fully materialised before it is sent. True request/response
streaming is a later ePHPm engine phase.

## Status

This driver is **designed and unit-tested against the documented laravel/octane
2.x interfaces, but has not been runtime-validated** against a live Laravel app in
this environment (no `php`/`composer`/Laravel available at authoring time). The
Envelope→Request and Response→`send_response()` mappings are covered by unit
tests that need neither the Laravel runtime nor the native worker primitives; the
end-to-end wiring should be verified against a real app before production use.

## Development

```bash
composer install
vendor/bin/phpunit
```

## License

MIT — see [LICENSE](LICENSE).
