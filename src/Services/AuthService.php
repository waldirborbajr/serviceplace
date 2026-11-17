<?php

declare(strict_types=1);

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
