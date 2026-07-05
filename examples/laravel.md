# Running a Laravel app under ePHPm with Octane

This is a documentation recipe. It requires a real Laravel app + Laravel Octane
and is not exercised in this package's CI.

## 1. Install the pieces

From your Laravel project root:

```bash
composer require laravel/octane
php artisan octane:install        # publishes Octane config
composer require ephpm/octane-driver
```

## 2. Add the ePHPm worker config

Create `ephpm.toml` at the project root:

```toml
[server]
listen        = "0.0.0.0:8080"
document_root = "."               # the Laravel project root

[php]
mode          = "worker"
worker_script = "vendor/bin/ephpm-octane-worker"
worker_count  = 1                 # raise for more concurrency
# EPHPM_APP_BASE=/srv/app         # optional env: pin the app base (dir with bootstrap/app.php)
```

`worker_script` must resolve **under** `document_root` (ePHPm rejects a script
that escapes the root), so `document_root` is the project root — which contains
`vendor/` — not `public/`. In worker mode every non-static request is routed to
the worker entrypoint, which boots Laravel and lets *its* router handle the URL;
you do not point `document_root` at `public/` the way php-fpm would. Run ePHPm
with its working directory at the project root (or set `EPHPM_APP_BASE`) so the
worker can locate `vendor/autoload.php` and `bootstrap/app.php`.

## 3. Start the server

```bash
ephpm serve
```

ePHPm spawns one or more persistent workers, each running
`vendor/bin/ephpm-octane-worker`. That script:

1. loads `vendor/autoload.php`,
2. finds `bootstrap/app.php`,
3. boots an Octane `Worker` with `Ephpm\Octane\EphpmClient`,
4. loops: `take_request()` -> Octane `handle()` -> `send_response()`.

Because the framework stays booted between requests, cold-boot cost is paid once
per worker, not once per request.

## 4. Worker-mode hygiene (same rules as any Octane driver)

State leaks between requests because the container is reused. Follow Octane's
guidance:

- Avoid storing per-request state in singletons.
- Reset any static caches you introduce.
- Use `Octane::` events / the `flush` list in `config/octane.php` to clear state.
- Be careful with `env()` outside config (cached across requests).

See the Laravel Octane docs on "Dependency Injection & Octane" and
"Managing Memory Leaks".

## 5. What is NOT wired up

`php artisan octane:start --server=ephpm` is **not** supported by this package.
ePHPm supervises workers itself, so start the server with `ephpm serve`. A
first-class Octane server binding (`ServerProcessInspector` + `ServerStateFile`)
is a documented follow-up.

## Troubleshooting

- **"could not locate vendor/autoload.php"** — run `composer install`, or point
  `worker_args` / `EPHPM_APP_BASE` at the project root.
- **"could not locate bootstrap/app.php"** — the app base autodetection failed;
  set `EPHPM_APP_BASE=/path/to/app` (the directory containing `bootstrap/`).
- **Blank 500s** — check your app log; `EphpmClient::error()` intentionally emits
  a generic body and leaves detail to Laravel's logging.
