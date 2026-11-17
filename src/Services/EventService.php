<?php

declare(strict_types=1);

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
