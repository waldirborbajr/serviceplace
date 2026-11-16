<?php

declare(strict_types=1);

// =============================================================================
// CONFIGURAÇÃO
// =============================================================================

define('JWT_SECRET', getenv('JWT_SECRET') ?: 'troque-este-segredo-em-producao');
define('JWT_EXPIRY', 3600 * 24); // 24h

// Abstração de banco: troque DB_DRIVER para 'mysql' e ajuste DB_DSN
// para migrar sem mexer no resto do código.
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
// Toda interação com banco passa por estas funções.
// Para trocar de driver: mude as constantes acima. Nada mais.
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

    // SQLite: habilita foreign keys (desabilitado por padrão no SQLite)
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
// MIGRATIONS (schema do banco + seed de dev)
// Rodadas uma vez na primeira requisição se as tabelas não existirem.
// =============================================================================

function db_migrate(): void {
    // Tabela unificada de usuários (clientes e fornecedores são o mesmo login)
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

    // Perfil extra para fornecedores
    db_run("CREATE TABLE IF NOT EXISTS provider_profiles (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id         INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
        category        TEXT    NOT NULL,
        description     TEXT,
        city            TEXT    NOT NULL,
        neighborhood    TEXT,
        lat             REAL,
        lng             REAL,
        available       INTEGER NOT NULL DEFAULT 1,  -- 0/1 booleano
        created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
        updated_at      TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    // Perfil extra para clientes
    db_run("CREATE TABLE IF NOT EXISTS client_profiles (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id         INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
        city            TEXT,
        neighborhood    TEXT,
        created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
        updated_at      TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    // -------------------------------------------------------------------------
    // SEED DE DEV (idempotente) — NÃO usar em produção
    // Usuário de teste: teste@test.com / 123 (role: client)
    // -------------------------------------------------------------------------
    $seedEmail = 'teste@test.com';
    if (!db_fetch('SELECT id FROM users WHERE email = ?', [$seedEmail])) {
        db_run(
            'INSERT INTO users (name, email, phone, password, role) VALUES (?, ?, ?, ?, ?)',
            [
                'Usuário Teste',
                $seedEmail,
                null,
                password_hash('123', PASSWORD_BCRYPT),
                'client',
            ]
        );
        $seedId = (int) db_last_id();

        db_run(
            'INSERT INTO client_profiles (user_id, city, neighborhood) VALUES (?, ?, ?)',
            [$seedId, 'São Paulo', 'Centro']
        );
    }
}

// =============================================================================
// JWT (implementação mínima — HS256, sem biblioteca externa)
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

    [$header, $body, $sig] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));

    // Comparação timing-safe
    if (!hash_equals($expected, $sig)) return null;

    $payload = json_decode(base64url_decode($body), true);
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
// AUTENTICAÇÃO — middleware
// =============================================================================

function auth_required(): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($header, 'Bearer ')) {
        http_response(401, ['error' => 'Token ausente']);
        exit;
    }

    $token   = substr($header, 7);
    $payload = jwt_decode($token);
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
// ROTEADOR MÍNIMO
// =============================================================================

function route(string $method, string $pattern, callable $handler): void {
    $uri    = strtok($_SERVER['REQUEST_URI'], '?');
    $method = strtoupper($method);

    // Converte :param em grupos de captura
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
    $body = require_fields(['name', 'email', 'password']);

    $email = strtolower(trim($body['email']));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response(422, ['error' => 'E-mail inválido']);
        return;
    }

    if (db_fetch('SELECT id FROM users WHERE email = ?', [$email])) {
        http_response(409, ['error' => 'E-mail já cadastrado']);
        return;
    }

    $hash = password_hash($body['password'], PASSWORD_BCRYPT);

    db_run(
        'INSERT INTO users (name, email, phone, password, role) VALUES (?, ?, ?, ?, ?)',
        [trim($body['name']), $email, $body['phone'] ?? null, $hash, 'client']
    );
    $user_id = (int) db_last_id();

    db_run(
        'INSERT INTO client_profiles (user_id, city, neighborhood) VALUES (?, ?, ?)',
        [$user_id, $body['city'] ?? null, $body['neighborhood'] ?? null]
    );

    $user = db_fetch('SELECT id, name, email, role, created_at FROM users WHERE id = ?', [$user_id]);
    http_response(201, ['user' => $user]);
}

// POST /auth/register/provider
function handle_register_provider(): void {
    $body = require_fields(['name', 'email', 'password', 'category', 'city']);

    $email = strtolower(trim($body['email']));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response(422, ['error' => 'E-mail inválido']);
        return;
    }

    if (db_fetch('SELECT id FROM users WHERE email = ?', [$email])) {
        http_response(409, ['error' => 'E-mail já cadastrado']);
        return;
    }

    $hash = password_hash($body['password'], PASSWORD_BCRYPT);

    db_run(
        'INSERT INTO users (name, email, phone, password, role) VALUES (?, ?, ?, ?, ?)',
        [trim($body['name']), $email, $body['phone'] ?? null, $hash, 'provider']
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
    $body  = require_fields(['email', 'password']);
    $email = strtolower(trim($body['email']));

    $user = db_fetch('SELECT * FROM users WHERE email = ?', [$email]);

    if (!$user || !password_verify($body['password'], $user['password'])) {
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

// GET /me  — retorna o usuário autenticado com seu perfil
function handle_me(): void {
    $auth = auth_required();
    $user = db_fetch(
        'SELECT id, name, email, phone, role, created_at FROM users WHERE id = ?',
        [$auth['sub']]
    );

    if ($user['role'] === 'provider') {
        $profile = db_fetch('SELECT * FROM provider_profiles WHERE user_id = ?', [$user['id']]);
    } else {
        $profile = db_fetch('SELECT * FROM client_profiles WHERE user_id = ?', [$user['id']]);
    }

    http_response(200, ['user' => $user, 'profile' => $profile]);
}

// GET /providers  — lista fornecedores (público, com filtros opcionais)
function handle_list_providers(): void {
    $city         = $_GET['city']     ?? null;
    $neighborhood = $_GET['neighborhood'] ?? null;
    $category     = $_GET['category'] ?? null;
    $available    = $_GET['available'] ?? null;

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
        $params[] = (int) $available;
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
