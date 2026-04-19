<?php
$path = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];

if ($path === '/api/users' && $method === 'GET') {
    http_response_code(200);
    header('Content-Type: application/json');
    echo '[{"id":1},{"id":2}]';
} elseif ($path === '/api/users' && $method === 'POST') {
    http_response_code(201);
    header('Content-Type: application/json');
    echo '{"id":3}';
} elseif ($path === '/health') {
    http_response_code(200);
    echo 'ok';
} elseif ($path === '/error') {
    http_response_code(500);
    echo 'error';
} else {
    http_response_code(404);
    echo 'not found';
}
