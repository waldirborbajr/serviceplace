<?php

declare(strict_types=1);

function handle_pay_event_professional(array $params): void {
    rate_limit('/payments', 10, 60);
    $auth  = require_role('client');
    $event = require_event_owner((int) $params['id'], (int) $auth['sub']);

    $body = require_fields(['event_professional_id']);

    $ep = db_fetch(
        'SELECT ep.*, u.name AS provider_name
         FROM event_professionals ep
         JOIN users u ON u.id = ep.provider_user_id
         WHERE ep.id = ? AND ep.event_id = ?',
        [(int) $body['event_professional_id'], $event['id']]
    );

    if (!$ep) {
        http_response(404, ['error' => 'Vínculo não encontrado para este evento']); return;
    }

    // Só paga vínculos confirmados (contratante já aceitou o prestador)
    if ($ep['status'] !== 'confirmed') {
        http_response(409, ['error' => "Só é possível pagar vínculos com status 'confirmed' (atual: {$ep['status']})"]); return;
    }

    // Sem pagamento duplicado
    $existing = db_fetch('SELECT id FROM payments WHERE event_professional_id = ?', [$ep['id']]);
    if ($existing) {
        http_response(409, ['error' => 'Este vínculo já possui um pagamento']); return;
    }

    // Valor: usa o price_cents do vínculo, ou o body, ou falha
    $amount = isset($body['amount_cents'])
        ? (int) $body['amount_cents']
        : (int) ($ep['price_cents'] ?? 0);

    if ($amount <= 0) {
        http_response(422, ['error' => 'Valor do pagamento inválido (price_cents do vínculo está vazio)']); return;
    }

    $fee    = intdiv($amount * PLATFORM_FEE_PERCENT, 100);
    $payout = $amount - $fee;

    // Simula o PaymentIntent do Stripe (idempotente pelo UNIQUE)
    $paymentIntentId = 'pi_sim_' . bin2hex(random_bytes(12));

    db_run(
        "INSERT INTO payments
            (event_id, client_user_id, payment_intent_id, amount_cents, currency, status,
             platform_fee_cents, provider_payout_cents, event_professional_id, paid_at)
         VALUES (?, ?, ?, ?, ?, 'held', ?, ?, ?, datetime('now'))",
        [
            $event['id'],
            $auth['sub'],
            $paymentIntentId,
            $amount,
            PAYMENT_CURRENCY,
            $fee,
            $payout,
            $ep['id'],
        ]
    );

    $paymentId = (int) db_last_id();
    $payment   = db_fetch('SELECT * FROM payments WHERE id = ?', [$paymentId]);

    http_response(201, [
        'payment' => $payment,
        'message' => "Pagamento de R$ " . number_format($amount / 100, 2, ',', '.') .
                     " retido na plataforma. O prestador receberá R$ " .
                     number_format($payout / 100, 2, ',', '.') .
                     " após a conclusão do serviço.",
    ]);
}

/**
 * POST /payments/:id/release
 * Contratante (ou sistema) libera o pagamento ao prestador após o serviço concluído.
 * Simula uma Transfer do Stripe Connect (Separate Transfers).
 */
function handle_release_payment(array $params): void {
    rate_limit('/payments', 10, 60);
    $auth = auth_required();

    $payment = db_fetch(
        'SELECT p.*, e.client_user_id, ep.status AS ep_status
         FROM payments p
         JOIN events e ON e.id = p.event_id
         JOIN event_professionals ep ON ep.id = p.event_professional_id
         WHERE p.id = ?',
        [(int) $params['id']]
    );

    if (!$payment) {
        http_response(404, ['error' => 'Pagamento não encontrado']); return;
    }

    // Só o contratante dono do evento pode liberar
    if ((int) $payment['client_user_id'] !== (int) $auth['sub']) {
        http_response(403, ['error' => 'Sem permissão para liberar este pagamento']); return;
    }

    if ($payment['status'] !== 'held') {
        http_response(409, ['error' => "Só é possível liberar pagamentos com status 'held' (atual: {$payment['status']})"]); return;
    }

    // O vínculo precisa estar 'done' (serviço concluído)
    if ($payment['ep_status'] !== 'done') {
        http_response(409, ['error' => "O serviço ainda não foi concluído (status do vínculo: {$payment['ep_status']})"]); return;
    }

    $transferId = 'tr_sim_' . bin2hex(random_bytes(12));

    db_run(
        "UPDATE payments
         SET status = 'released', transfer_id = ?, released_at = datetime('now'),
             updated_at = datetime('now')
         WHERE id = ?",
        [$transferId, $payment['id']]
    );

    http_response(200, [
        'payment' => db_fetch('SELECT * FROM payments WHERE id = ?', [$payment['id']]),
        'message' => "Transferência de R$ " . number_format($payment['provider_payout_cents'] / 100, 2, ',', '.') .
                     " liberada ao prestador.",
    ]);
}

/**
 * GET /events/:id/payments
 * Dono do evento vê todos os pagamentos do evento.
 */
function handle_list_event_payments(array $params): void {
    rate_limit('/payments', 60, 60);
    $auth  = require_role('client');
    $event = require_event_owner((int) $params['id'], (int) $auth['sub']);

    $payments = db_fetch_all(
        'SELECT p.*,
                (SELECT u.name FROM users u
                 JOIN event_professionals ep ON ep.provider_user_id = u.id
                 WHERE ep.id = p.event_professional_id) AS provider_name,
                (SELECT ep.role FROM event_professionals ep
                 WHERE ep.id = p.event_professional_id) AS role
         FROM payments p
         WHERE p.event_id = ?
         ORDER BY p.created_at ASC',
        [$event['id']]
    );

    http_response(200, ['payments' => $payments]);
}

/**
 * GET /me/payments
 * Prestador vê seus repasses (released) e valores retidos (held).
 */
function handle_my_provider_payments(): void {
    rate_limit('/payments', 60, 60);
    $auth = require_role('provider');

    $rows = db_fetch_all(
        'SELECT p.id, p.payment_intent_id, p.amount_cents, p.platform_fee_cents,
                p.provider_payout_cents, p.status, p.paid_at, p.released_at,
                e.title AS event_title, e.starts_at, ep.role
         FROM payments p
         JOIN events e ON e.id = p.event_id
         JOIN event_professionals ep ON ep.id = p.event_professional_id
         WHERE ep.provider_user_id = ?
         ORDER BY p.created_at DESC',
        [$auth['sub']]
    );

    http_response(200, ['payments' => $rows]);
}
