<?php

declare(strict_types=1);

function http_response(int $status, array $data): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function request_body(): array {
    static $body = null;
    if ($body !== null) return $body;
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];
    return $body;
}

function require_fields(array $fields): array {
    $body    = request_body();
    $missing = [];
    foreach ($fields as $f) {
        if (empty($body[$f])) $missing[] = $f;
    }
    if ($missing) {
        http_response(422, ['error' => 'Campos obrigatórios ausentes', 'fields' => $missing]);
        exit;
    }
    return $body;
}

// =============================================================================
// ROTEADOR
// =============================================================================

function route(string $method, string $pattern, callable $handler): void {
    $uri    = strtok($_SERVER['REQUEST_URI'], '?');
    $method = strtoupper($method);

    $regex = preg_replace('/:([a-zA-Z_]+)/', '(?P<$1>[^/]+)', $pattern);
    $regex = "#^{$regex}$#";

    if ($_SERVER['REQUEST_METHOD'] === $method && preg_match($regex, $uri, $m)) {
        $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
        $handler($params);
        exit;
    }
}
