<?php

declare(strict_types=1);

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
