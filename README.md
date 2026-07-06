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

ePHPm packages are distributed via their GitHub repositories (not Packagist).
Add every ePHPm repo in the dependency tree as a Composer `vcs` repository, then
require the driver. This package pulls in `ephpm/worker`, so **both** repos are
listed — Composer does **not** resolve a VCS dependency's own VCS repositories
transitively, so each ePHPm package in the tree needs its own `repositories`
entry in your app's `composer.json`.

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/ephpm/octane-driver" },
    { "type": "vcs", "url": "https://github.com/ephpm/php-worker" }
  ],
  "require": {
    "ephpm/octane-driver": "^0.1"
  }
}
```

Both `ephpm/octane-driver` and its `ephpm/worker` dependency are tagged
`v0.1.0`, so `^0.1` resolves for each; each still needs its own `repositories`
entry because Composer does not resolve VCS repos transitively. Then:

```bash
composer update
```

You also need Laravel Octane installed and published in your app:

```bash
composer require laravel/octane
php artisan octane:install
```

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
`Symfony\Component\HttpFoundation\Request`, then lifts it with
`Illuminate\Http\Request::createFromBase()`. The engine hands over raw material
only — `Envelope::parsedBody()` is always `null`, `files()` is always empty, and
`query()`/`cookies()` are not url-decoded — so the driver does the parsing
itself: the query string is re-parsed with `parse_str()` (url-decodes, handles
`a[]=`), cookie names/values are url-decoded, `application/x-www-form-urlencoded`
bodies are parsed for `POST`/`PUT`/`PATCH`/`DELETE`, and `multipart/form-data`
bodies are parsed into fields plus uploaded files (spooled to temp files that are
unlinked after the request).

**Response** (`EphpmClient::respond`): prepends Octane's captured `$outputBuffer`
(anything echoed outside the `Response`) to the body and flattens
`allPreserveCaseWithoutCookies()` headers to `['Name' => 'v1, v2']`. Queued
cookies are sent as a **list** under `Set-Cookie`
(`['Set-Cookie' => [$c1, $c2]]`) so the engine emits one wire header per cookie —
comma-joining would corrupt `expires=` attributes. Buffered responses go through
`send_response()`; `StreamedResponse` / `BinaryFileResponse` are streamed through
`send_response_stream()` (see below).

**Error** (`EphpmClient::error`): emits a generic `500 Internal Server Error`
without leaking the exception (your app's logging surfaces the detail).

## RequestContext note

Octane owns its own `Laravel\Octane\RequestContext` class (a loosely-typed bag
with `public array $data`). We cannot replace it, so we carry the ePHPm envelope
inside it at `data['envelope']`. This package also ships a thin, strongly-typed
`Ephpm\Octane\RequestContext` value object (with `fromOctane()` / `toOctane()`)
used by the worker loop and tests — see its class docs for the deviation
rationale.

## Streaming

Streaming **responses** are supported: `BinaryFileResponse` is streamed straight
from its file, and `StreamedResponse` output is captured into a `php://temp`
stream (PHP spools it to disk past ~2 MB, keeping memory flat) — both are sent
via `send_response_stream()`, which the engine forwards in 64 KB chunks with
backpressure. The **request** body is still buffered as a string
(`Envelope::rawBody()`).

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
