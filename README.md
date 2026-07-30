# CodeTracer PHP Recorder

A PHP extension that records execution into a CodeTracer `.ct` container, and
marks every HTTP request the worker serves as a **span** over that recording's
own timeline.

One worker holds **one continuous recording**. Eight requests are eight
intervals of it, not eight recordings in eight directories — which is what
lets the Request Panel seek from a row straight into that request's own steps,
in the same container the rest of the session lives in.

## Usage

Load the extension and enable recording:

```bash
CODETRACER_ENABLED=1 php \
    -d extension=/path/to/ext/modules/codetracer.so \
    -d auto_prepend_file=/path/to/src/web_bootstrap.php \
    -S localhost:8095 -t your/docroot
```

In a php-fpm pool:

```ini
php_admin_value[extension] = /path/to/ext/modules/codetracer.so
php_admin_value[auto_prepend_file] = /path/to/src/web_bootstrap.php
```

`web_bootstrap.php` is **optional**. Without it the extension still emits a
complete `web-request` span per request — method, URL, status, duration,
response size, remote address and the step range. The bootstrap only adds what
the extension cannot observe for itself: the framework name, the matched route
pattern and an application error message.

### Environment variables

- `CODETRACER_ENABLED=1` — record at all.
- `CODETRACER_OUTPUT_DIR` — where the container is written.
- `CODETRACER_FRAMEWORK` — value of the `framework` span metadata key
  (`plain`, `laravel`, ...), read by `web_bootstrap.php`.
- `CODETRACER_ROUTE` — the matched route pattern, if the application knows it.

### Annotating from application code

```php
codetracer_span_annotate('http.route', '/api/users/{user_id}');
codetracer_span_annotate('error.message', 'RuntimeException: ...');
```

Both land as metadata on the current request's span. A Laravel middleware and
an `auto_prepend_file` doing this are in `tests/programs/web/laravel/`.

Read the spans back with the canonical Nim decoder:

```php
$spans = json_decode(codetracer_spans_json($containerPath), true);
```

## Running tests

```bash
just test                # extension smoke + e2e + request spans
just test-request-spans  # RS-M7 request-span integration tests only
just test-orphans        # report test files no recipe runs
```

## Architecture

- `ext/codetracer_php.c` — the extension. Publishes an **open** span record at
  `PHP_RINIT` and settles it at `PHP_RSHUTDOWN` under the same `span_id`;
  readers collapse the pair last-record-wins.
- `src/web_bootstrap.php` — optional `auto_prepend_file` that annotates the
  current span with framework / route / error message.
- `src/ct_runtime.php`, `src/preprocessor.php` — the separate pure-PHP
  source-instrumentation path.
- `src/recorder.php` — placeholder for future function-level tracing.

## Span format

Spans live in the container's `spans.dat` / `spans.idx` / `spantype.ns`
streams, specified by
`codetracer-specs/Trace-Files/CTFS-Request-Span-Streams.md`. A settled
`web-request` span carries at least:

| Key                  | Example                    |
| -------------------- | -------------------------- |
| `http.method`        | `GET`                      |
| `http.url`           | `/api/users/2`             |
| `http.status_code`   | `200`                      |
| `http.duration_ms`   | `42`                       |
| `http.response_size` | `2148`                     |
| `http.remote_addr`   | `127.0.0.1`                |
| `http.route`         | `/api/users/{user_id}`     |
| `framework`          | `plain`                    |
| `error.message`      | set when the handler threw |

### The JSONL sidecar is gone (RS-M12)

Before RS-M7 this recorder shipped a pure-PHP `CodeTracerSpan` class that
appended request metadata to a `codetracer_spans.jsonl` file and never touched
a container. RS-M7 moved spans into the container; RS-M12 removed the writer,
along with `src/span.php`, `src/auto_prepend.php` and their tests. Nothing
here writes `codetracer_spans.jsonl` or `session_manifest.jsonl` any more, and
`CODETRACER_SPAN_MANIFEST` is no longer read.

Sessions recorded *before* the change are still readable: CodeTracer's
db-backend keeps a read-only shim
(`codetracer/src/db-backend/src/request_spans.rs`) that parses both sidecar
files into the same records the span stream produces.
