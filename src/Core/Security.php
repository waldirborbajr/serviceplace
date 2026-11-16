<?php

declare(strict_types=1);

function rate_limit(string $endpoint, int $maxAttempts = RATE_LIMIT_DEFAULT_MAX, int $windowSeconds = RATE_LIMIT_DEFAULT_WINDOW): void {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $now = time();

    $row = db_fetch(
        'SELECT id, attempts, window_start FROM rate_limits WHERE ip = ? AND endpoint = ?',
        [$ip, $endpoint]
    );

    if ($row) {
        $elapsed = $now - strtotime($row['window_start']);
        if ($elapsed > $windowSeconds) {
            db_run(
                'UPDATE rate_limits SET attempts = 1, window_start = ? WHERE id = ?',
                [date('Y-m-d H:i:s', $now), $row['id']]
            );
        } elseif ((int) $row['attempts'] >= $maxAttempts) {
            http_response(429, ['error' => 'Muitas requisições. Tente novamente mais tarde.']);
            exit;
        } else {
            db_run('UPDATE rate_limits SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
        }
    } else {
        db_run(
            'INSERT INTO rate_limits (ip, endpoint, attempts, window_start) VALUES (?, ?, 1, ?)',
            [$ip, $endpoint, date('Y-m-d H:i:s', $now)]
        );
    }
}

// =============================================================================
// JWT (HS256, algoritmo fixo)
// =============================================================================

function jwt_encode(array $payload): string {
    $header  = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['exp'] = time() + JWT_EXPIRY;
    $body    = base64url_encode(json_encode($payload));
    $sig     = base64url_encode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    return "$header.$body.$sig";
}

function jwt_decode(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    [$header64, $body64, $sig64] = $parts;

    $header = json_decode(base64url_decode($header64), true);
    if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
        return null;
    }

    $expected = base64url_encode(hash_hmac('sha256', "$header64.$body64", JWT_SECRET, true));
    if (!hash_equals($expected, $sig64)) return null;

    $payload = json_decode(base64url_decode($body64), true);
    if (!$payload || ($payload['exp'] ?? 0) < time()) return null;

    return $payload;
}

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

// =============================================================================
// AUTENTICAÇÃO
// =============================================================================

function auth_required(): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($header, 'Bearer ')) {
        http_response(401, ['error' => 'Token ausente']);
        exit;
    }

    $payload = jwt_decode(substr($header, 7));
    if (!$payload) {
        http_response(401, ['error' => 'Token inválido ou expirado']);
        exit;
    }

    return $payload;
}
