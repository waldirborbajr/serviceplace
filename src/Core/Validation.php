<?php

declare(strict_types=1);

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

function validate_payment_status(string $s): bool {
    return in_array($s, ['pending', 'held', 'released', 'refunded', 'failed'], true);
}
