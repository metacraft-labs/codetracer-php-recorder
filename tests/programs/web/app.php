<?php
/**
 * The plain built-in-server demo app for RS-M7.
 *
 * Run as the router script of PHP's built-in server:
 *
 *     php -S 127.0.0.1:8080 tests/programs/web/app.php
 *
 * Every request is handled by this one file, so `php -S` stays a single
 * process serving a stream of requests — which is exactly the shape RS-M7
 * records: ONE continuous recording for the worker, partitioned into one
 * `web-request` span per request.
 *
 * The routes below mirror the Python (Flask) and Ruby (Sinatra) demo
 * schedules request-for-request, so the Request Panel shows the same session
 * in every language: a 2xx, a 201, a parameterised route, a 304, a 404, a
 * deliberately slow handler, and a 500 that carries `error.message`.
 *
 * `codetracer_span_annotate()` is how the app tells the recorder the things
 * the C extension cannot know on its own — the route PATTERN (not the
 * concrete URL), the framework name, and the error message.  It is a no-op
 * that returns false when nothing is being recorded, so this file also runs
 * unrecorded.
 */

declare(strict_types=1);

/** Annotate the in-flight request span, if there is one. */
function ct_annotate(string $key, string $value): void
{
    if (function_exists('codetracer_span_annotate')) {
        codetracer_span_annotate($key, $value);
    }
}

/** The demo's user table. A real function so the recording has real steps. */
function demo_users(): array
{
    return [
        ['id' => 1, 'name' => 'ada'],
        ['id' => 2, 'name' => 'grace'],
        ['id' => 3, 'name' => 'linus'],
    ];
}

function find_user(int $id): ?array
{
    foreach (demo_users() as $user) {
        if ($user['id'] === $id) {
            return $user;
        }
    }
    return null;
}

function json_response(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
}

function handle_list_users(): void
{
    ct_annotate('http.route', '/api/users');
    json_response(200, ['users' => demo_users()]);
}

function handle_create_user(): void
{
    ct_annotate('http.route', '/api/users');
    json_response(201, ['created' => ['id' => 4, 'name' => 'margaret']]);
}

function handle_show_user(int $id): void
{
    // The PATTERN, not the concrete URL: two different ids must report the
    // same route, which is what makes the panel's route column useful.
    ct_annotate('http.route', '/api/users/{user_id}');
    $user = find_user($id);
    if ($user === null) {
        json_response(404, ['error' => 'no such user']);
        return;
    }
    json_response(200, ['user' => $user]);
}

function handle_static_asset(): void
{
    ct_annotate('http.route', '/static/app.css');
    // 304 has no body by definition — the span's response_size is 0 and the
    // panel colours the row in its "redirect" bucket.
    http_response_code(304);
}

function slow_sum(int $rounds): int
{
    $total = 0;
    for ($i = 0; $i < $rounds; $i++) {
        $total += $i % 7;
        usleep(1000);
    }
    return $total;
}

function handle_slow_report(): void
{
    ct_annotate('http.route', '/api/reports/slow');
    $total = slow_sum(50);
    json_response(200, ['total' => $total]);
}

function handle_boom(): void
{
    ct_annotate('http.route', '/api/boom');
    // A handler that fails on purpose.  The status AND the message are
    // recorded, and they are independent: a 404 above carries a 4xx status
    // with no error message at all.
    ct_annotate('error.message', 'RuntimeException: demo failure in /api/boom');
    json_response(500, ['error' => 'demo failure in /api/boom']);
}

function handle_not_found(string $path): void
{
    json_response(404, ['error' => "no route for $path"]);
}

function dispatch(string $method, string $path): void
{
    ct_annotate('framework', 'plain');

    if ($path === '/api/users' && $method === 'GET') {
        handle_list_users();
        return;
    }
    if ($path === '/api/users' && $method === 'POST') {
        handle_create_user();
        return;
    }
    if (preg_match('#^/api/users/(\d+)$#', $path, $m) === 1 && $method === 'GET') {
        handle_show_user((int) $m[1]);
        return;
    }
    if ($path === '/static/app.css') {
        handle_static_asset();
        return;
    }
    if ($path === '/api/reports/slow') {
        handle_slow_report();
        return;
    }
    if ($path === '/api/boom') {
        handle_boom();
        return;
    }
    handle_not_found($path);
}

$__ct_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$__ct_uri = $_SERVER['REQUEST_URI'] ?? '/';
$__ct_path = parse_url($__ct_uri, PHP_URL_PATH) ?: '/';
dispatch($__ct_method, $__ct_path);
