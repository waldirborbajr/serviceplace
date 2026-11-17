<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

http_response(404, ['error' => 'Rota não encontrada']);
