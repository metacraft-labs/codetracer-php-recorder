# Web demo programs (RS-M7)

The applications and harness behind the PHP request-panel milestone.

| Path | What it is |
| --- | --- |
| `app.php` | The plain built-in-server demo app. A router script for `php -S`, with the same route schedule the Python (Flask) and Ruby (Sinatra) demos use, so the Request Panel shows the same session in every language. |
| `session_driver.php` | The harness. Starts a recorded `php -S` process or a recorded php-fpm pool, drives real HTTP / FastCGI traffic at it, waits for the workers to write their containers, and decodes the spans back through the canonical Nim reader. Shared by `tests/test_request_spans.php`, `just demo-request-panel-php` and `just record-request-panel-fixture` so the three cannot drift. |
| `laravel/CodeTracerRequestSpan.php` | Laravel middleware: adds `http.route`, `framework` and `error.message` to the span the extension is already recording. |
| `laravel/codetracer_prepend.php` | The same annotations via `auto_prepend_file`, for a Laravel app you cannot edit. |

## What the recorder does on its own

Since RS-M7 the C extension holds one trace writer for the worker's whole
lifetime and marks each request as a `web-request` span over that continuous
timeline. It observes, with no application cooperation at all:

`http.method`, `http.url`, `http.status_code`, `http.duration_ms`,
`http.response_size`, `http.remote_addr`, the wall-clock interval, and the
`[start_step, end_step]` range the request occupies in the recording.

The application (or a framework middleware) contributes what the extension
cannot know, through `codetracer_span_annotate(string $key, string $value)`:
`http.route`, `framework`, `error.message`.

## Running the plain demo by hand

```sh
just build
php -d extension=ext/modules/codetracer.so \
    tests/programs/web/session_driver.php \
    --trace-dir /tmp/ct-php-demo --print-spans
```

## Running the Laravel demo

Laravel is installed with Composer, which this repo's dev shell does not
provide, so the Laravel demo runs against an application you supply:

```sh
composer create-project laravel/laravel /tmp/ct-laravel
cd /tmp/ct-laravel

# Either register the middleware (Laravel 11+ bootstrap/app.php):
#     ->withMiddleware(fn (Middleware $m) =>
#         $m->append(\CodeTracer\Laravel\CodeTracerRequestSpan::class))
# after copying CodeTracerRequestSpan.php into app/Http/Middleware/ …

# … or use the zero-touch prepend, which needs no source change:
CODETRACER_ENABLED=1 CODETRACER_OUTPUT_DIR=/tmp/ct-laravel-trace \
  php -d extension=/path/to/ext/modules/codetracer.so \
      -d auto_prepend_file=/path/to/laravel/codetracer_prepend.php \
      -S 127.0.0.1:8080 -t public public/index.php
```

Then `ct replay -t /tmp/ct-laravel-trace/worker_<pid>`.

## Server shapes and how each one ends

Nothing appears on disk until `trace_writer_close()` runs, so how a server is
stopped decides whether its recording survives. Measured against php 8.4.21:

| Server | Stop signal | Where the container is written |
| --- | --- | --- |
| `php -S` | **SIGINT** (it installs no SIGTERM handler) | `PHP_MSHUTDOWN` |
| php-fpm worker | **SIGQUIT** from the pool master | the extension's own signal handler — FPM children do not reliably reach `PHP_MSHUTDOWN`, and do not run `atexit` handlers either |
| php-fpm worker | `pm.max_requests` recycle | `PHP_MSHUTDOWN` |
| anything | SIGKILL | nowhere: the worker's whole recording is lost |

php-fpm's default `process_control_timeout = 0` escalates
SIGQUIT → SIGTERM → SIGKILL with no gap between them, which gives no worker
time to shut down. `session_driver.php` sets a real timeout in the pool config
it generates; a production pool that wants its recordings should do the same.
