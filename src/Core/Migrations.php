<?php

declare(strict_types=1);

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
    // PAGAMENTOS (simulação Stripe Connect: Separate Charges and Transfers)
    // -------------------------------------------------------------------------
    db_run("CREATE TABLE IF NOT EXISTS payments (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id        INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
        client_user_id  INTEGER NOT NULL REFERENCES users(id),
        -- Stripe PaymentIntent simulado
        payment_intent_id TEXT    NOT NULL UNIQUE,   -- ex: 'pi_sim_<hex>'
        amount_cents    INTEGER NOT NULL,            -- valor total cobrado do contratante
        currency        TEXT    NOT NULL DEFAULT 'brl',
        status          TEXT    NOT NULL DEFAULT 'pending'
                        CHECK(status IN ('pending','held','released','refunded','failed')),
        -- Distribuição (Separate Transfers)
        platform_fee_cents INTEGER NOT NULL,         -- taxa retida pela plataforma
        provider_payout_cents INTEGER NOT NULL,      -- valor a repassar ao prestador
        -- Referência ao vínculo que está sendo pago
        event_professional_id INTEGER NOT NULL REFERENCES event_professionals(id),
        -- Simulação de transferência ao prestador
        transfer_id     TEXT    UNIQUE,              -- ex: 'tr_sim_<hex>' (null enquanto held)
        paid_at         TEXT,                        -- quando o contratante pagou
        released_at     TEXT,                        -- quando a plataforma liberou ao prestador
        created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
        updated_at      TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    db_run("CREATE INDEX IF NOT EXISTS idx_payments_event ON payments(event_id)");
    db_run("CREATE INDEX IF NOT EXISTS idx_payments_status ON payments(status)");
    db_run("CREATE INDEX IF NOT EXISTS idx_payments_ep ON payments(event_professional_id)");

    // -------------------------------------------------------------------------
    // SEED DE DEV (idempotente) — NÃO usar em produção
    //   contratante: cliente@test.com / 123
    //   prestadores: fotografo@test.com / garcom@test.com / dj@test.com (senha 123)
    //   1 evento do contratante com os 3 prestadores conectados em estados distintos
    //   1 pagamento 'held' para o fotógrafo (confirmed), pronto para teste de release
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
    $epIds = [];
    foreach ($links as [$providerId, $role, $status, $message, $priceCents]) {
        $exists = db_fetch(
            'SELECT id FROM event_professionals WHERE event_id = ? AND provider_user_id = ? AND role = ?',
            [$eventId, $providerId, $role]
        );
        if ($exists) {
            $epIds[] = (int) $exists['id'];
            continue;
        }
        db_run(
            'INSERT INTO event_professionals
                (event_id, provider_user_id, role, status, message, price_cents)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$eventId, $providerId, $role, $status, $message, $priceCents]
        );
        $epIds[] = (int) db_last_id();
    }

    // ---- Pagamento simulado (fotógrafo, confirmed) -------------------------
    // Cria um PaymentIntent simulado em status 'held' para o vínculo do fotógrafo.
    // Permite testar GET /events/:id/payments e POST /payments/:id/release
    // (este último exige que o vínculo esteja 'done' antes).
    $existingPay = db_fetch('SELECT id FROM payments WHERE event_professional_id = ?', [$epIds[0]]);
    if (!$existingPay) {
        $amount = 1500000;
        $fee    = intdiv($amount * PLATFORM_FEE_PERCENT, 100);
        $payout = $amount - $fee;
        db_run(
            "INSERT INTO payments
                (event_id, client_user_id, payment_intent_id, amount_cents, currency, status,
                 platform_fee_cents, provider_payout_cents, event_professional_id, paid_at)
             VALUES (?, ?, ?, ?, ?, 'held', ?, ?, ?, datetime('now'))",
            [
                $eventId,
                $clientId,
                'pi_sim_seed_' . bin2hex(random_bytes(8)),
                $amount,
                PAYMENT_CURRENCY,
                $fee,
                $payout,
                $epIds[0],
            ]
        );
    }
}
