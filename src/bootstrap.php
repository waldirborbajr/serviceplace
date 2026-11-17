<?php

declare(strict_types=1);

require_once __DIR__ . '/Core/Configuration.php';
require_once __DIR__ . '/Core/Database.php';
require_once __DIR__ . '/Core/Validation.php';
require_once __DIR__ . '/Core/Security.php';
require_once __DIR__ . '/Core/Http.php';
require_once __DIR__ . '/Core/Authorization.php';
require_once __DIR__ . '/Core/Migrations.php';

require_once __DIR__ . '/Services/AuthService.php';
require_once __DIR__ . '/Services/ProviderService.php';
require_once __DIR__ . '/Services/EventService.php';
require_once __DIR__ . '/Services/MatchmakingService.php';
require_once __DIR__ . '/Services/PaymentService.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

db_migrate();

require __DIR__ . '/Routes/auth.php';
require __DIR__ . '/Routes/providers.php';
require __DIR__ . '/Routes/events.php';
require __DIR__ . '/Routes/matchmaking.php';
require __DIR__ . '/Routes/payments.php';
require __DIR__ . '/Routes/provider-events.php';
