<?php

declare(strict_types=1);

// =============================================================================
// CONFIGURAÇÃO
// =============================================================================

define('JWT_SECRET', getenv('JWT_SECRET') ?: 'dev-secret-troque-em-producao');
define('JWT_EXPIRY', 3600); // 1h
define('PASSWORD_PEPPER', getenv('PASSWORD_PEPPER') ?: 'dev-pepper-troque-em-producao');

define('RATE_LIMIT_DEFAULT_MAX', 60);
define('RATE_LIMIT_DEFAULT_WINDOW', 60);

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

function validate_iso8601(string $s): bool {
    return preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2})?)?$/', $s) === 1;
}

function validate_event_status(string $s): bool {
    return in_array($s, ['draft', 'open', 'filled', 'cancelled', 'done'], true);
}

// =============================================================================
// MIGRATIONS (schema + seed de dev)
// =============================================================================

function db_migrate(): void {
    // -------------------------------------------------------------------------
    // users / perfis
    // -------------------------------------------------------------------------
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

    // -------------------------------------------------------------------------
    // eventos (MVP)
    // -------------------------------------------------------------------------
    db_run("CREATE TABLE IF NOT EXISTS events (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        client_user_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        title           TEXT    NOT NULL,
        description     TEXT,
        category        TEXT    NOT NULL,
        starts_at       TEXT    NOT NULL,
        ends_at         TEXT,
        city            TEXT    NOT NULL,
        neighborhood    TEXT,
        address         TEXT,
        lat             REAL,
        lng             REAL,
        guest_count     INTEGER,
        budget_cents    INTEGER,
        status          TEXT    NOT NULL DEFAULT 'draft'
                        CHECK(status IN ('draft','open','filled','cancelled','done')),
        created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
        updated_at      TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    db_run("CREATE INDEX IF NOT EXISTS idx_events_client ON events(client_user_id)");
    db_run("CREATE INDEX IF NOT EXISTS idx_events_status ON events(status)");
    db_run("CREATE INDEX IF NOT EXISTS idx_events_city   ON events(city)");

    // -------------------------------------------------------------------------
    // conexão N:N evento <-> prestador
    // -------------------------------------------------------------------------
    db_run("CREATE TABLE IF NOT EXISTS event_professionals (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id         INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
        provider_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        role             TEXT    NOT NULL,
        status           TEXT    NOT NULL DEFAULT 'invited'
                         CHECK(status IN ('invited','applied','accepted','declined','confirmed','done','cancelled')),
        message          TEXT,
        price_cents      INTEGER,
        created_at       TEXT    NOT NULL DEFAULT (datetime('now')),
        updated_at       TEXT    NOT NULL DEFAULT (datetime('now')),
        UNIQUE(event_id, provider_user_id, role)
    )");
    db_run("CREATE INDEX IF NOT EXISTS idx_ep_event    ON event_professionals(event_id)");
    db_run("CREATE INDEX IF NOT EXISTS idx_ep_provider ON event_professionals(provider_user_id)");
    db_run("CREATE INDEX IF NOT EXISTS idx_ep_status   ON event_professionals(status)");

    // -------------------------------------------------------------------------
    // rate limiting
    // -------------------------------------------------------------------------
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
    //   contratante: cliente@test.com / 123
    //   prestadores: fotografo@test.com / garcom@test.com / dj@test.com (senha 123)
    //   1 evento do contratante com os 3 prestadores conectados em estados distintos
    // -------------------------------------------------------------------------
    seed_dev_data();
}

function seed_dev_data(): void {
    // ---- Contratante -------------------------------------------------------
    $clientEmail = 'cliente@test.com';
    $client = db_fetch('SELECT id FROM users WHERE email = ?', [$clientEmail]);
    if (!$client) {
        db_run(
            'INSERT INTO users (name, email, phone, password, role) VALUES (?, ?, ?, ?, ?)',
            ['Cliente Teste', $clientEmail, '+5511999990000', hash_password('123'), 'client']
        );
        $clientId = (int) db_last_id();
        db_run(
            'INSERT INTO client_profiles (user_id, city, neighborhood) VALUES (?, ?, ?)',
            [$clientId, 'São Paulo', 'Jardins']
        );
    } else {
        $clientId = (int) $client['id'];
    }

    // ---- Prestadores -------------------------------------------------------
    // category, city, neighborhood, description
    $providersSeed = [
        ['Fotógrafo Teste',  'fotografo@test.com', 'fotografia', 'São Paulo', 'Jardins', 'Fotografia de casamentos e eventos corporativos.'],
        ['Garçom Teste',     'garcom@test.com',    'garcom',     'São Paulo', 'Jardins', 'Serviço de garçom para eventos de até 200 pessoas.'],
        ['DJ Teste',         'dj@test.com',        'dj',         'São Paulo', 'Jardins', 'DJ para festas e casamentos, set list personalizado.'],
    ];

    $providerIds = [];
    foreach ($providersSeed as [$name, $email, $category, $city, $neighborhood, $description]) {
        $existing = db_fetch('SELECT id FROM users WHERE email = ?', [$email]);
        if ($existing) {
            $providerIds[] = (int) $existing['id'];
            continue;
        }
        db_run(
            'INSERT INTO users (name, email, phone, password, role) VALUES (?, ?, ?, ?, ?)',
            [$name, $email, null, hash_password('123'), 'provider']
        );
        $pid = (int) db_last_id();
        db_run(
            'INSERT INTO provider_profiles (user_id, category, description, city, neighborhood, available)
             VALUES (?, ?, ?, ?, ?, 1)',
            [$pid, $category, $description, $city, $neighborhood]
        );
        $providerIds[] = $pid;
    }

    // ---- Evento ------------------------------------------------------------
    $existingEvent = db_fetch(
        'SELECT id FROM events WHERE client_user_id = ? AND title = ?',
        [$clientId, 'Casamento Ana & João']
    );
    if ($existingEvent) {
        $eventId = (int) $existingEvent['id'];
    } else {
        db_run(
            "INSERT INTO events
                (client_user_id, title, description, category, starts_at, ends_at,
                 city, neighborhood, address, guest_count, budget_cents, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $clientId,
                'Casamento Ana & João',
                'Casamento ao ar livre, 120 convidados, cerimônia às 18h e festa até 1h.',
                'casamento',
                '2026-12-31T18:00:00',
                '2027-01-01T01:00:00',
                'São Paulo',
                'Jardins',
                'Rua Exemplo, 123',
                120,
                5000000, // R$ 50.000,00
                'open',
            ]
        );
        $eventId = (int) db_last_id();
    }

    // ---- Conexões evento <-> prestadores -----------------------------------
    // Estados distintos para você ver o fluxo completo:
    //   fotografo -> confirmed   (contratante confirmou)
    //   garcom    -> accepted    (prestador aceitou convite)
    //   dj        -> applied     (prestador se candidatou)
    $links = [
        [$providerIds[0], 'fotografo', 'confirmed',  'Confirmado pelo contratante.', 1500000],
        [$providerIds[1], 'garcom',    'accepted',   'Aceito. Disponível no dia.',   800000],
        [$providerIds[2], 'dj',        'applied',    'Tenho interesse, posso enviar set list?', 1200000],
    ];
    foreach ($links as [$providerId, $role, $status, $message, $priceCents]) {
        $exists = db_fetch(
            'SELECT id FROM event_professionals WHERE event_id = ? AND provider_user_id = ? AND role = ?',
            [$eventId, $providerId, $role]
        );
        if ($exists) continue;
        db_run(
            'INSERT INTO event_professionals
                (event_id, provider_user_id, role, status, message, price_cents)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$eventId, $providerId, $role, $status, $message, $priceCents]
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
// HELPERS DE AUTORIZAÇÃO
// =============================================================================

function require_role(string $role): array {
    $auth = auth_required();
    if (($auth['role'] ?? '') !== $role) {
        http_response(403, ['error' => "Requer role '$role'"]);
        exit;
    }
    return $auth;
}

function require_event_owner(int $eventId, int $userId): array {
    $event = db_fetch('SELECT * FROM events WHERE id = ?', [$eventId]);
    if (!$event) {
        http_response(404, ['error' => 'Evento não encontrado']);
        exit;
    }
    if ((int) $event['client_user_id'] !== $userId) {
        http_response(403, ['error' => 'Você não é o dono deste evento']);
        exit;
    }
    return $event;
}

// =============================================================================
// HANDLERS — AUTH
// =============================================================================

function handle_register_client(): void {
    rate_limit('/auth/register', 10, 300);

    $body = require_fields(['name', 'email', 'password']);

    $email = strtolower(trim($body['email']));
    if (!validate_email($email)) {
        http_response(422, ['error' => 'E-mail inválido']); return;
    }
    if (!validate_str_len($body['name'], 1, 120)) {
        http_response(422, ['error' => 'Nome deve ter entre 1 e 120 caracteres']); return;
    }
    if (!validate_phone($body['phone'] ?? null)) {
        http_response(422, ['error' => 'Telefone inválido (E.164, ex: +5511999999999)']); return;
    }
    if (!validate_str_len($body['password'], 6, 200)) {
        http_response(422, ['error' => 'Senha deve ter entre 6 e 200 caracteres']); return;
    }
    if (db_fetch('SELECT id FROM users WHERE email = ?', [$email])) {
        http_response(409, ['error' => 'E-mail já cadastrado']); return;
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

function handle_register_provider(): void {
    rate_limit('/auth/register', 10, 300);

    $body = require_fields(['name', 'email', 'password', 'category', 'city']);

    $email = strtolower(trim($body['email']));
    if (!validate_email($email)) {
        http_response(422, ['error' => 'E-mail inválido']); return;
    }
    if (!validate_str_len($body['name'], 1, 120)) {
        http_response(422, ['error' => 'Nome deve ter entre 1 e 120 caracteres']); return;
    }
    if (!validate_phone($body['phone'] ?? null)) {
        http_response(422, ['error' => 'Telefone inválido (E.164)']); return;
    }
    if (!validate_str_len($body['password'], 6, 200)) {
        http_response(422, ['error' => 'Senha deve ter entre 6 e 200 caracteres']); return;
    }
    if (!validate_str_len($body['category'], 1, 80)) {
        http_response(422, ['error' => 'Categoria deve ter entre 1 e 80 caracteres']); return;
    }
    if (!validate_str_len($body['city'], 1, 100)) {
        http_response(422, ['error' => 'Cidade deve ter entre 1 e 100 caracteres']); return;
    }
    if (db_fetch('SELECT id FROM users WHERE email = ?', [$email])) {
        http_response(409, ['error' => 'E-mail já cadastrado']); return;
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

function handle_login(): void {
    rate_limit('/auth/login', 5, 300);

    $body  = require_fields(['email', 'password']);
    $email = strtolower(trim($body['email']));

    if (!validate_email($email)) {
        http_response(422, ['error' => 'E-mail inválido']); return;
    }

    $user = db_fetch('SELECT * FROM users WHERE email = ?', [$email]);

    if (!$user || !verify_password($body['password'], $user['password'])) {
        http_response(401, ['error' => 'Credenciais inválidas']); return;
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

function handle_me(): void {
    rate_limit('/me', 60, 60);

    $auth = auth_required();
    $user = db_fetch(
        'SELECT id, name, email, phone, role, created_at FROM users WHERE id = ?',
        [$auth['sub']]
    );
    if (!$user) {
        http_response(404, ['error' => 'Usuário não encontrado']); return;
    }

    if ($user['role'] === 'provider') {
        $profile = db_fetch('SELECT * FROM provider_profiles WHERE user_id = ?', [$user['id']]);
    } else {
        $profile = db_fetch('SELECT * FROM client_profiles WHERE user_id = ?', [$user['id']]);
    }

    http_response(200, ['user' => $user, 'profile' => $profile]);
}

// =============================================================================
// HANDLERS — PROVIDERS (vitrine)
// =============================================================================

function handle_list_providers(): void {
    rate_limit('/providers', 60, 60);

    $city         = $_GET['city'] ?? null;
    $neighborhood = $_GET['neighborhood'] ?? null;
    $category     = $_GET['category'] ?? null;
    $available    = $_GET['available'] ?? null;

    foreach ([
        'city'         => [$city, 100],
        'neighborhood' => [$neighborhood, 100],
        'category'     => [$category, 80],
    ] as $name => [$value, $max]) {
        if ($value !== null && !validate_str_len($value, 1, $max)) {
            http_response(422, ['error' => "Filtro '$name' inválido (máx $max caracteres)"]); return;
        }
    }

    if ($available !== null) {
        $validated = filter_var($available, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 1],
        ]);
        if ($validated === false) {
            http_response(422, ['error' => "Filtro 'available' deve ser 0 ou 1"]); return;
        }
        $available = $validated;
    }

    $sql = '
        SELECT u.id, u.name, u.phone,
               p.category, p.description, p.city, p.neighborhood,
               p.lat, p.lng, p.available
        FROM users u
        JOIN provider_profiles p ON p.user_id = u.id
        WHERE 1=1
    ';
    $params = [];
    if ($city)         { $sql .= ' AND LOWER(p.city) = LOWER(?)';         $params[] = $city; }
    if ($neighborhood) { $sql .= ' AND LOWER(p.neighborhood) = LOWER(?)'; $params[] = $neighborhood; }
    if ($category)     { $sql .= ' AND LOWER(p.category) = LOWER(?)';     $params[] = $category; }
    if ($available !== null) { $sql .= ' AND p.available = ?';            $params[] = $available; }

    http_response(200, ['providers' => db_fetch_all($sql, $params)]);
}

// =============================================================================
// HANDLERS — EVENTS (contratante)
// =============================================================================

// POST /events
function handle_create_event(): void {
    rate_limit('/events', 30, 60);
    $auth = require_role('client');

    $body = require_fields(['title', 'category', 'starts_at', 'city']);

    if (!validate_str_len($body['title'], 1, 200)) {
        http_response(422, ['error' => 'Título deve ter entre 1 e 200 caracteres']); return;
    }
    if (!validate_str_len($body['category'], 1, 80)) {
        http_response(422, ['error' => 'Categoria deve ter entre 1 e 80 caracteres']); return;
    }
    if (!validate_iso8601($body['starts_at'])) {
        http_response(422, ['error' => 'starts_at deve ser ISO 8601 (YYYY-MM-DD ou YYYY-MM-DDTHH:MM)']); return;
    }
    if (isset($body['ends_at']) && !validate_iso8601($body['ends_at'])) {
        http_response(422, ['error' => 'ends_at deve ser ISO 8601']); return;
    }
    if (!validate_str_len($body['city'], 1, 100)) {
        http_response(422, ['error' => 'Cidade deve ter entre 1 e 100 caracteres']); return;
    }

    $status = $body['status'] ?? 'draft';
    if (!validate_event_status($status)) {
        http_response(422, ['error' => 'Status inválido']); return;
    }

    db_run(
        'INSERT INTO events
            (client_user_id, title, description, category, starts_at, ends_at,
             city, neighborhood, address, lat, lng, guest_count, budget_cents, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $auth['sub'],
            trim($body['title']),
            $body['description'] ?? null,
            trim($body['category']),
            $body['starts_at'],
            $body['ends_at'] ?? null,
            trim($body['city']),
            $body['neighborhood'] ?? null,
            $body['address'] ?? null,
            isset($body['lat']) ? (float) $body['lat'] : null,
            isset($body['lng']) ? (float) $body['lng'] : null,
            isset($body['guest_count']) ? (int) $body['guest_count'] : null,
            isset($body['budget_cents']) ? (int) $body['budget_cents'] : null,
            $status,
        ]
    );
    $eventId = (int) db_last_id();
    $event   = db_fetch('SELECT * FROM events WHERE id = ?', [$eventId]);
    http_response(201, ['event' => $event]);
}

// GET /events
function handle_list_my_events(): void {
    rate_limit('/events', 60, 60);
    $auth = require_role('client');

    $status = $_GET['status'] ?? null;
    if ($status !== null && !validate_event_status($status)) {
        http_response(422, ['error' => 'Status inválido']); return;
    }

    $sql    = 'SELECT * FROM events WHERE client_user_id = ?';
    $params = [$auth['sub']];
    if ($status !== null) {
        $sql .= ' AND status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY starts_at DESC';

    http_response(200, ['events' => db_fetch_all($sql, $params)]);
}

// GET /events/:id
function handle_get_event(array $params): void {
    rate_limit('/events', 60, 60);
    $auth  = auth_required();
    $event = db_fetch('SELECT * FROM events WHERE id = ?', [$params['id']]);
    if (!$event) {
        http_response(404, ['error' => 'Evento não encontrado']); return;
    }

    // Dono vê tudo. Prestador só vê se estiver conectado. Público vê só eventos 'open'.
    if ((int) $event['client_user_id'] === (int) $auth['sub']) {
        // ok
    } elseif (($auth['role'] ?? '') === 'provider') {
        $linked = db_fetch(
            'SELECT id FROM event_professionals WHERE event_id = ? AND provider_user_id = ? LIMIT 1',
            [$event['id'], $auth['sub']]
        );
        if (!$linked && $event['status'] !== 'open') {
            http_response(403, ['error' => 'Sem permissão para ver este evento']); return;
        }
    } elseif ($event['status'] !== 'open') {
        http_response(403, ['error' => 'Sem permissão para ver este evento']); return;
    }

    $professionals = db_fetch_all(
        'SELECT ep.id, ep.role, ep.status, ep.message, ep.price_cents,
                u.id AS provider_user_id, u.name AS provider_name, u.phone AS provider_phone
         FROM event_professionals ep
         JOIN users u ON u.id = ep.provider_user_id
         WHERE ep.event_id = ?
         ORDER BY ep.created_at ASC',
        [$event['id']]
    );

    http_response(200, ['event' => $event, 'professionals' => $professionals]);
}

// PATCH /events/:id
function handle_update_event(array $params): void {
    rate_limit('/events', 30, 60);
    $auth  = require_role('client');
    $event = require_event_owner((int) $params['id'], (int) $auth['sub']);

    $body    = request_body();
    $allowed = ['title', 'description', 'category', 'starts_at', 'ends_at',
                'city', 'neighborhood', 'address', 'lat', 'lng',
                'guest_count', 'budget_cents', 'status'];
    $set     = [];
    $values  = [];

    foreach ($allowed as $field) {
        if (!array_key_exists($field, $body)) continue;

        $value = $body[$field];

        if ($field === 'title' && !validate_str_len($value, 1, 200)) {
            http_response(422, ['error' => 'Título inválido']); return;
        }
        if ($field === 'category' && !validate_str_len($value, 1, 80)) {
            http_response(422, ['error' => 'Categoria inválida']); return;
        }
        if (in_array($field, ['starts_at', 'ends_at'], true) && !validate_iso8601($value)) {
            http_response(422, ['error' => "$field deve ser ISO 8601"]); return;
        }
        if ($field === 'city' && !validate_str_len($value, 1, 100)) {
            http_response(422, ['error' => 'Cidade inválida']); return;
        }
        if ($field === 'status' && !validate_event_status($value)) {
            http_response(422, ['error' => 'Status inválido']); return;
        }

        $set[]    = "$field = ?";
        $values[] = $value;
    }

    if (!$set) {
        http_response(422, ['error' => 'Nenhum campo válido para atualizar']); return;
    }

    $set[]    = "updated_at = datetime('now')";
    $values[] = $event['id'];

    db_run('UPDATE events SET ' . implode(', ', $set) . ' WHERE id = ?', $values);
    $updated = db_fetch('SELECT * FROM events WHERE id = ?', [$event['id']]);
    http_response(200, ['event' => $updated]);
}

// DELETE /events/:id  (soft delete)
function handle_cancel_event(array $params): void {
    rate_limit('/events', 30, 60);
    $auth  = require_role('client');
    $event = require_event_owner((int) $params['id'], (int) $auth['sub']);

    db_run("UPDATE events SET status = 'cancelled', updated_at = datetime('now') WHERE id = ?", [$event['id']]);
    http_response(200, ['event' => db_fetch('SELECT * FROM events WHERE id = ?', [$event['id']])]);
}

// GET /events/open  (vitrine pública para prestadores)
function handle_list_open_events(): void {
    rate_limit('/events/open', 60, 60);

    $city     = $_GET['city'] ?? null;
    $category = $_GET['category'] ?? null;

    if ($city !== null && !validate_str_len($city, 1, 100)) {
        http_response(422, ['error' => 'Filtro city inválido']); return;
    }
    if ($category !== null && !validate_str_len($category, 1, 80)) {
        http_response(422, ['error' => 'Filtro category inválido']); return;
    }

    $sql    = "SELECT * FROM events WHERE status = 'open'";
    $params = [];
    if ($city)     { $sql .= ' AND LOWER(city) = LOWER(?)';         $params[] = $city; }
    if ($category) { $sql .= ' AND LOWER(category) = LOWER(?)';     $params[] = $category; }
    $sql .= ' ORDER BY starts_at ASC';

    http_response(200, ['events' => db_fetch_all($sql, $params)]);
}

// =============================================================================
// HANDLERS — EVENT PROFESSIONALS (matchmaking)
// =============================================================================

// POST /events/:id/professionals  (contratante convida prestador)
function handle_invite_professional(array $params): void {
    rate_limit('/events', 60, 60);
    $auth  = require_role('client');
    $event = require_event_owner((int) $params['id'], (int) $auth['sub']);
    $body  = require_fields(['provider_user_id', 'role']);

    $providerId = (int) $body['provider_user_id'];
    $provider   = db_fetch("SELECT id, role FROM users WHERE id = ? AND role = 'provider'", [$providerId]);
    if (!$provider) {
        http_response(422, ['error' => 'Prestador não encontrado']); return;
    }
    if (!validate_str_len($body['role'], 1, 80)) {
        http_response(422, ['error' => 'role inválido']); return;
    }

    if (db_fetch(
        'SELECT id FROM event_professionals WHERE event_id = ? AND provider_user_id = ? AND role = ?',
        [$event['id'], $providerId, $body['role']]
    )) {
        http_response(409, ['error' => 'Prestador já conectado a este evento neste papel']); return;
    }

    db_run(
        'INSERT INTO event_professionals (event_id, provider_user_id, role, status, message, price_cents)
         VALUES (?, ?, ?, ?, ?, ?)',
        [
            $event['id'], $providerId, trim($body['role']), 'invited',
            $body['message'] ?? null,
            isset($body['price_cents']) ? (int) $body['price_cents'] : null,
        ]
    );
    http_response(201, ['event_professional' => db_fetch(
        'SELECT * FROM event_professionals WHERE id = ?', [db_last_id()]
    )]);
}

// POST /events/:id/apply  (prestador se candidata)
function handle_apply_to_event(array $params): void {
    rate_limit('/events', 60, 60);
    $auth  = require_role('provider');
    $event = db_fetch('SELECT * FROM events WHERE id = ?', [$params['id']]);
    if (!$event) {
        http_response(404, ['error' => 'Evento não encontrado']); return;
    }
    if ($event['status'] !== 'open') {
        http_response(409, ['error' => 'Evento não está aberto para candidaturas']); return;
    }

    $body = require_fields(['role']);
    if (!validate_str_len($body['role'], 1, 80)) {
        http_response(422, ['error' => 'role inválido']); return;
    }

    if (db_fetch(
        'SELECT id FROM event_professionals WHERE event_id = ? AND provider_user_id = ? AND role = ?',
        [$event['id'], $auth['sub'], $body['role']]
    )) {
        http_response(409, ['error' => 'Você já se candidatou a este evento neste papel']); return;
    }

    db_run(
        'INSERT INTO event_professionals (event_id, provider_user_id, role, status, message, price_cents)
         VALUES (?, ?, ?, ?, ?, ?)',
        [
            $event['id'], $auth['sub'], trim($body['role']), 'applied',
            $body['message'] ?? null,
            isset($body['price_cents']) ? (int) $body['price_cents'] : null,
        ]
    );
    http_response(201, ['event_professional' => db_fetch(
        'SELECT * FROM event_professionals WHERE id = ?', [db_last_id()]
    )]);
}

// GET /events/:id/professionals  (dono vê todos)
function handle_list_event_professionals(array $params): void {
    rate_limit('/events', 60, 60);
    $auth  = require_role('client');
    $event = require_event_owner((int) $params['id'], (int) $auth['sub']);

    $rows = db_fetch_all(
        'SELECT ep.*, u.name AS provider_name, u.email AS provider_email, u.phone AS provider_phone
         FROM event_professionals ep
         JOIN users u ON u.id = ep.provider_user_id
         WHERE ep.event_id = ?
         ORDER BY ep.created_at ASC',
        [$event['id']]
    );
    http_response(200, ['professionals' => $rows]);
}

// PATCH /events/:id/professionals/:pid  (muda status)
function handle_update_event_professional(array $params): void {
    rate_limit('/events', 60, 60);
    $auth  = auth_required();

    $row = db_fetch(
        'SELECT ep.*, e.client_user_id
         FROM event_professionals ep
         JOIN events e ON e.id = ep.event_id
         WHERE ep.id = ? AND ep.event_id = ?',
        [$params['pid'], $params['id']]
    );
    if (!$row) {
        http_response(404, ['error' => 'Vínculo não encontrado']); return;
    }

    $body   = require_fields(['status']);
    $status = $body['status'];
    $valid  = ['invited','applied','accepted','declined','confirmed','done','cancelled'];
    if (!in_array($status, $valid, true)) {
        http_response(422, ['error' => 'Status inválido']); return;
    }

    $isOwner    = (int) $row['client_user_id'] === (int) $auth['sub'];
    $isProvider = (int) $row['provider_user_id'] === (int) $auth['sub'];

    // Quem pode mudar o quê:
    //   - prestador: aceita/recusa convite (invited -> accepted|declined), cancela o próprio
    //   - contratante: confirma candidatura (applied -> confirmed), marca done, cancela
    $allowedByProvider = ['invited' => ['accepted', 'declined'],
                          'applied' => ['cancelled'],
                          'accepted' => ['cancelled'],
                          'confirmed' => ['done']];
    $allowedByClient   = ['applied' => ['confirmed', 'declined'],
                          'invited' => ['cancelled'],
                          'accepted' => ['confirmed', 'cancelled'],
                          'confirmed' => ['done', 'cancelled']];

    $current = $row['status'];
    $permitted = false;
    if ($isProvider && isset($allowedByProvider[$current]) && in_array($status, $allowedByProvider[$current], true)) {
        $permitted = true;
    }
    if ($isOwner && isset($allowedByClient[$current]) && in_array($status, $allowedByClient[$current], true)) {
        $permitted = true;
    }
    if (!$permitted) {
        http_response(403, ['error' => "Transição de '$current' para '$status' não permitida"]); return;
    }

    db_run("UPDATE event_professionals SET status = ?, updated_at = datetime('now') WHERE id = ?",
           [$status, $row['id']]);

    http_response(200, ['event_professional' => db_fetch(
        'SELECT * FROM event_professionals WHERE id = ?', [$row['id']]
    )]);
}

// GET /me/events  (prestador vê seus vínculos)
function handle_my_provider_events(): void {
    rate_limit('/me/events', 60, 60);
    $auth = require_role('provider');

    $rows = db_fetch_all(
        "SELECT ep.id AS link_id, ep.role, ep.status, ep.price_cents,
                e.id AS event_id, e.title, e.starts_at, e.city, e.status AS event_status
         FROM event_professionals ep
         JOIN events e ON e.id = ep.event_id
         WHERE ep.provider_user_id = ?
         ORDER BY e.starts_at ASC",
        [$auth['sub']]
    );
    http_response(200, ['links' => $rows]);
}

// =============================================================================
// BOOTSTRAP
// =============================================================================

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

db_migrate();

// --- Auth ---
route('POST', '/auth/register/client',   'handle_register_client');
route('POST', '/auth/register/provider', 'handle_register_provider');
route('POST', '/auth/login',             'handle_login');
route('GET',  '/me',                     'handle_me');

// --- Vitrine ---
route('GET',  '/providers',              'handle_list_providers');

// --- Eventos (contratante) ---
route('POST',   '/events',               'handle_create_event');
route('GET',    '/events',               'handle_list_my_events');
route('GET',    '/events/open',          'handle_list_open_events');
route('GET',    '/events/:id',           'handle_get_event');
route('PATCH',  '/events/:id',           'handle_update_event');
route('DELETE', '/events/:id',           'handle_cancel_event');

// --- Matchmaking ---
route('GET',   '/events/:id/professionals',      'handle_list_event_professionals');
route('POST',  '/events/:id/professionals',      'handle_invite_professional');
route('PATCH', '/events/:id/professionals/:pid', 'handle_update_event_professional');
route('POST',  '/events/:id/apply',              'handle_apply_to_event');

// --- Prestador ---
route('GET',   '/me/events',             'handle_my_provider_events');

// 404 fallback
http_response(404, ['error' => 'Rota não encontrada']);
