<?php

declare(strict_types=1);

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
