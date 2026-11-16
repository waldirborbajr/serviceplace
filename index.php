<?php

declare(strict_types=1);

// =============================================================================
// CONFIGURAÇÃO
// =============================================================================

define('JWT_SECRET', getenv('JWT_SECRET') ?: 'dev-secret-troque-em-producao');
define('JWT_EXPIRY', 3600); // 1h (reduzido de 24h por segurança)
define('PASSWORD_PEPPER', getenv('PASSWORD_PEPPER') ?: 'dev-pepper-troque-em-producao');

// Rate limiting: defaults por endpoint
define('RATE_LIMIT_DEFAULT_MAX', 60);   // 60 req
define('RATE_LIMIT_DEFAULT_WINDOW', 60); // por 60s

// Abstração de banco: troque DB_DRIVER para 'mysql' e ajuste DB_DSN
define('DB_DRIVER', 'sqlite');
define('DB_DSN',    'sqlite:' . __DIR__ . '/data/platform.db');
define('DB_USER',   null);
define('DB_PASS',   null);

// Para MySQL seria:
// define('DB_DRIVER', 'mysql');
// define('DB_DSN',    'mysql:host=mysql;dbname=platform;charset=utf8mb4');
// define('DB_USER',   'platform');
// define('DB_PASS',   'platform');

// =============================================================================
// CAMADA DE BANCO DE DADOS
// =============================================================================

function db_connect(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, $options);

    if (DB_DRIVER === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    return $pdo;
}

function db_run(string $sql, array $params = []): \PDOStatement {
    $stmt = db_connect()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function db_fetch(string $sql, array $params = []): ?array {
    return db_run($sql, $params)->fetch() ?: null;
}

function db_fetch_all(string $sql, array $params = []): array {
    return db_run($sql, $params)->fetchAll();
}

function db_last_id(): string {
    return db_connect()->lastInsertId();
}

// =============================================================================
// SENHAS — salt & pepper
// =============================================================================

// OWASP: pré-hash com HMAC-SHA384 usando pepper, depois bcrypt (que gera salt).
function hash_password(string $plain): string {
    $peppered = hash_hmac('sha384', $plain, PASSWORD_PEPPER, true);
    return password_hash($peppered, PASSWORD_BCRYPT);
}

function verify_password(string $plain, string $stored): bool {
    $peppered = hash_hmac('sha384', $plain, PASSWORD_PEPPER, true);
    return password_verify($peppered, $stored);
}

// =============================================================================
// VALIDAÇÃO DE FORMATO
// =============================================================================

function validate_email(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validate_phone(?string $phone): bool {
    if ($phone === null || $phone === '') return true;
    return preg_match('/^\+?[1-9]\d{1,14}$/', $phone) === 1;
}

function validate_role(string $role): bool {
    return in_array($role, ['client', 'provider'], true);
}

function validate_str_len(?string $s, int $min, int $max): bool {
    if ($s === null) return $min === 0;
    $len = mb_strlen($s, 'UTF-8');
    return $len >= $min && $len <= $max;
}

// =============================================================================
// MIGRATIONS (schema + seed de dev)
// =============================================================================

function db_migrate(): void {
    db_run("CREATE TABLE IF NOT EXISTS users (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL,
        email       TEXT    NOT NULL UNIQUE,
        phone       TEXT,
        password    TEXT    NOT NULL,
        role        TEXT    NOT NULL CHECK(role IN ('client','provider')),
        created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
        updated_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    db_run("CREATE TABLE IF NOT EXISTS provider_profiles (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id         INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
        category        TEXT    NOT NULL,
        description     TEXT,
        city            TEXT    NOT NULL,
        neighborhood    TEXT,
        lat             REAL,
        lng             REAL,
        available       INTEGER NOT NULL DEFAULT 1,
        created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
        updated_at      TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    db_run("CREATE TABLE IF NOT EXISTS client_profiles (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id         INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
        city            TEXT,
        neighborhood    TEXT,
        created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
        updated_at      TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    // Rate limiting
    db_run("CREATE TABLE IF NOT EXISTS rate_limits (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        ip           TEXT    NOT NULL,
        endpoint     TEXT    NOT NULL,
        attempts     INTEGER NOT NULL DEFAULT 0,
        window_start TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    db_run("CREATE UNIQUE INDEX IF NOT EXISTS idx_rate_ip_endpoint ON rate_limits(ip, endpoint)");

    // -------------------------------------------------------------------------
    // SEED DE DEV (idempotente) — NÃO usar em produção
    // -------------------------------------------------------------------------
    $seedEmail = 'teste@test.com';
    if (!db_fetch('SELECT id FROM users WHERE email = ?', [$seedEmail])) {
        db_run(
            'INSERT INTO users (name, email, phone, password, role) VALUES (?, ?, ?, ?, ?)',
            ['Usuário Teste', $seedEmail, null, hash_password('123'), 'client']
        );
        $seedId = (int) db_last_id();

        db_run(
            'INSERT INTO client_profiles (user_id, city, neighborhood) VALUES (?, ?, ?)',
            [$seedId, 'São Paulo', 'Centro']
        );
    }
}

// =============================================================================
// RATE LIMITING
// =============================================================================

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
            // Janela expirou: reseta
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

    // 1. Algoritmo fixo: rejeita qualquer header que não declare HS256.
    $header = json_decode(base64url_decode($header64), true);
    if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
        return null;
    }

    // 2. Assinatura timing-safe.
    $expected = base64url_encode(hash_hmac('sha256', "$header64.$body64", JWT_SECRET, true));
    if (!hash_equals($expected, $sig64)) return null;

    // 3. Expiração.
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

// =============================================================================
// HTTP HELPERS
// =============================================================================

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

// =============================================================================
// HANDLERS
// =============================================================================

// POST /auth/register/client
function handle_register_client(): void {
    rate_limit('/auth/register', 10, 300); // 10 registros por 5 min por IP

    $body = require_fields(['name', 'email', 'password']);

    $email = strtolower(trim($body['email']));
    if (!validate_email($email)) {
        http_response(422, ['error' => 'E-mail inválido']);
        return;
    }
    if (!validate_str_len($body['name'], 1, 120)) {
        http_response(422, ['error' => 'Nome deve ter entre 1 e 120 caracteres']);
        return;
    }
    if (!validate_phone($body['phone'] ?? null)) {
        http_response(422, ['error' => 'Telefone inválido (use formato E.164, ex: +5511999999999)']);
        return;
    }
    if (!validate_str_len($body['password'], 6, 200)) {
        http_response(422, ['error' => 'Senha deve ter entre 6 e 200 caracteres']);
        return;
    }

    if (db_fetch('SELECT id FROM users WHERE email = ?', [$email])) {
        http_response(409, ['error' => 'E-mail já cadastrado']);
        return;
    }

    db_run(
        'INSERT INTO users (name, email, phone, password, role) VALUES (?, ?, ?, ?, ?)',
        [trim($body['name']), $email, $body['phone'] ?? null, hash_password($body['password']), 'client']
    );
    $user_id = (int) db_last_id();

    db_run(
        'INSERT INTO client_profiles (user_id, city, neighborhood) VALUES (?, ?, ?)',
        [
            $user_id,
            isset($body['city']) ? trim($body['city']) : null,
            isset($body['neighborhood']) ? trim($body['neighborhood']) : null,
        ]
    );

    $user = db_fetch('SELECT id, name, email, role, created_at FROM users WHERE id = ?', [$user_id]);
    http_response(201, ['user' => $user]);
}

// POST /auth/register/provider
function handle_register_provider(): void {
    rate_limit('/auth/register', 10, 300);

    $body = require_fields(['name', 'email', 'password', 'category', 'city']);

    $email = strtolower(trim($body['email']));
    if (!validate_email($email)) {
        http_response(422, ['error' => 'E-mail inválido']);
        return;
    }
    if (!validate_str_len($body['name'], 1, 120)) {
        http_response(422, ['error' => 'Nome deve ter entre 1 e 120 caracteres']);
        return;
    }
    if (!validate_phone($body['phone'] ?? null)) {
        http_response(422, ['error' => 'Telefone inválido (use formato E.164, ex: +5511999999999)']);
        return;
    }
    if (!validate_str_len($body['password'], 6, 200)) {
        http_response(422, ['error' => 'Senha deve ter entre 6 e 200 caracteres']);
        return;
    }
    if (!validate_str_len($body['category'], 1, 80)) {
        http_response(422, ['error' => 'Categoria deve ter entre 1 e 80 caracteres']);
        return;
    }
    if (!validate_str_len($body['city'], 1, 100)) {
        http_response(422, ['error' => 'Cidade deve ter entre 1 e 100 caracteres']);
        return;
    }

    if (db_fetch('SELECT id FROM users WHERE email = ?', [$email])) {
        http_response(409, ['error' => 'E-mail já cadastrado']);
        return;
    }

    db_run(
        'INSERT INTO users (name, email, phone, password, role) VALUES (?, ?, ?, ?, ?)',
        [trim($body['name']), $email, $body['phone'] ?? null, hash_password($body['password']), 'provider']
    );
    $user_id = (int) db_last_id();

    db_run(
        'INSERT INTO provider_profiles (user_id, category, description, city, neighborhood, lat, lng)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [
            $user_id,
            trim($body['category']),
            $body['description'] ?? null,
            trim($body['city']),
            $body['neighborhood'] ?? null,
            isset($body['lat']) ? (float) $body['lat'] : null,
            isset($body['lng']) ? (float) $body['lng'] : null,
        ]
    );

    $user = db_fetch('SELECT id, name, email, role, created_at FROM users WHERE id = ?', [$user_id]);
    http_response(201, ['user' => $user]);
}

// POST /auth/login
function handle_login(): void {
    rate_limit('/auth/login', 5, 300); // 5 tentativas por 5 min por IP

    $body  = require_fields(['email', 'password']);
    $email = strtolower(trim($body['email']));

    if (!validate_email($email)) {
        http_response(422, ['error' => 'E-mail inválido']);
        return;
    }

    $user = db_fetch('SELECT * FROM users WHERE email = ?', [$email]);

    if (!$user || !verify_password($body['password'], $user['password'])) {
        http_response(401, ['error' => 'Credenciais inválidas']);
        return;
    }

    $token = jwt_encode([
        'sub'  => $user['id'],
        'role' => $user['role'],
        'name' => $user['name'],
    ]);

    http_response(200, [
        'token' => $token,
        'user'  => [
            'id'    => $user['id'],
            'name'  => $user['name'],
            'email' => $user['email'],
            'role'  => $user['role'],
        ],
    ]);
}

// GET /me
function handle_me(): void {
    rate_limit('/me', 60, 60);

    $auth = auth_required();
    $user = db_fetch(
        'SELECT id, name, email, phone, role, created_at FROM users WHERE id = ?',
        [$auth['sub']]
    );

    if (!$user) {
        http_response(404, ['error' => 'Usuário não encontrado']);
        return;
    }

    if ($user['role'] === 'provider') {
        $profile = db_fetch('SELECT * FROM provider_profiles WHERE user_id = ?', [$user['id']]);
    } else {
        $profile = db_fetch('SELECT * FROM client_profiles WHERE user_id = ?', [$user['id']]);
    }

    http_response(200, ['user' => $user, 'profile' => $profile]);
}

// GET /providers
function handle_list_providers(): void {
    rate_limit('/providers', 60, 60);

    $city         = $_GET['city'] ?? null;
    $neighborhood = $_GET['neighborhood'] ?? null;
    $category     = $_GET['category'] ?? null;
    $available    = $_GET['available'] ?? null;

    // Validação de comprimento dos filtros
    foreach ([
        'city'         => [$city, 100],
        'neighborhood' => [$neighborhood, 100],
        'category'     => [$category, 80],
    ] as $name => [$value, $max]) {
        if ($value !== null && !validate_str_len($value, 1, $max)) {
            http_response(422, ['error' => "Filtro '$name' inválido (máx $max caracteres)"]);
            return;
        }
    }

    // available deve ser 0 ou 1
    if ($available !== null) {
        $validated = filter_var($available, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 1],
        ]);
        if ($validated === false) {
            http_response(422, ['error' => "Filtro 'available' deve ser 0 ou 1"]);
            return;
        }
        $available = $validated;
    }

    $sql    = '
        SELECT u.id, u.name, u.phone,
               p.category, p.description, p.city, p.neighborhood,
               p.lat, p.lng, p.available
        FROM users u
        JOIN provider_profiles p ON p.user_id = u.id
        WHERE 1=1
    ';
    $params = [];

    if ($city) {
        $sql .= ' AND LOWER(p.city) = LOWER(?)';
        $params[] = $city;
    }
    if ($neighborhood) {
        $sql .= ' AND LOWER(p.neighborhood) = LOWER(?)';
        $params[] = $neighborhood;
    }
    if ($category) {
        $sql .= ' AND LOWER(p.category) = LOWER(?)';
        $params[] = $category;
    }
    if ($available !== null) {
        $sql .= ' AND p.available = ?';
        $params[] = $available;
    }

    $providers = db_fetch_all($sql, $params);
    http_response(200, ['providers' => $providers]);
}

// =============================================================================
// BOOTSTRAP
// =============================================================================

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

db_migrate();

// --- Rotas ---
route('POST', '/auth/register/client',   'handle_register_client');
route('POST', '/auth/register/provider', 'handle_register_provider');
route('POST', '/auth/login',             'handle_login');
route('GET',  '/me',                     'handle_me');
route('GET',  '/providers',              'handle_list_providers');

// 404 fallback
http_response(404, ['error' => 'Rota não encontrada']);
