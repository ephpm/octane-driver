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
listen = "0.0.0.0:8080"

[php]
mode          = "worker"
worker_script = "vendor/bin/ephpm-octane-worker"
document_root = "public"          # Laravel's public/ directory
# worker_args = ["/srv/app"]      # optional: pin the app base (dir with bootstrap/app.php)
```

`document_root = "public"` lets ePHPm serve Laravel's static assets directly
(and route everything else to `public/index.php`'s equivalent worker path).

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
