<?php

declare(strict_types=1);

define('JWT_SECRET', getenv('JWT_SECRET') ?: 'dev-secret-troque-em-producao');
define('JWT_EXPIRY', 3600); // 1h
define('PASSWORD_PEPPER', getenv('PASSWORD_PEPPER') ?: 'dev-pepper-troque-em-producao');

define('RATE_LIMIT_DEFAULT_MAX', 60);
define('RATE_LIMIT_DEFAULT_WINDOW', 60);

define('DB_DRIVER', 'sqlite');
define('DB_DSN',    'sqlite:' . dirname(__DIR__, 2) . '/data/platform.db');
define('DB_USER',   null);
define('DB_PASS',   null);

// Para MySQL seria:
// define('DB_DRIVER', 'mysql');
// define('DB_DSN',    'mysql:host=mysql;dbname=platform;charset=utf8mb4');
// define('DB_USER',   'platform');
// define('DB_PASS',   'platform');

// Simulação de Stripe Connect (Separate Charges and Transfers)
define('PLATFORM_FEE_PERCENT', 10); // 10% de taxa da plataforma
define('PAYMENT_CURRENCY', 'brl');
