# CodeTracer PHP Recorder

Pure-PHP recorder that captures HTTP request spans. Each PHP request invocation produces a span written to a JSONL manifest file, following the same format as the Ruby and Python recorders.

## Usage

### With auto_prepend_file (recommended)

Add to your `php.ini` or php-fpm pool config:

```ini
auto_prepend_file = /path/to/codetracer-php-recorder/src/auto_prepend.php
```

Or pass it on the command line:

```bash
php -d auto_prepend_file=src/auto_prepend.php your_script.php
```

### Environment variables

- `CODETRACER_SPAN_MANIFEST` -- path to the JSONL output file (default: `/tmp/codetracer_spans.jsonl`)

### Programmatic usage

```php
require_once 'src/span.php';

$span = CodeTracerSpan::begin();
// ... your code ...
$span->end(http_response_code() ?: 200);
```

## Running tests

```bash
just test
```

Or directly:

```bash
php tests/test_span.php
```

## Architecture

- `src/span.php` -- HTTP request span tracking (primary deliverable)
- `src/auto_prepend.php` -- Bootstrap for automatic span capture via `auto_prepend_file`
- `src/recorder.php` -- Placeholder for future function-level tracing (will require a C extension)

## Span format

Each line in the JSONL manifest is a JSON object:

```json
{
  "id": "span_php_12345_1234567890",
  "label": "GET /api/users",
  "span_type": "web-request",
  "metadata": {
    "http.method": "GET",
    "http.url": "/api/users",
    "http.status_code": "200",
    "http.duration_ms": "42"
  },
  "status": "ok",
  "end_time": "2026-04-17T12:00:00Z"
}
```
