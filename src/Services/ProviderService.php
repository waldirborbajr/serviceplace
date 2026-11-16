<?php

declare(strict_types=1);

function handle_list_providers(): void {
    rate_limit('/providers', 60, 60);

    $city         = $_GET['city'] ?? null;
    $neighborhood = $_GET['neighborhood'] ?? null;
    $category     = $_GET['category'] ?? null;
    $available    = $_GET['available'] ?? null;

    foreach ([
        'city'         => [$city, 100],
        'neighborhood' => [$neighborhood, 100],
        'category'     => [$category, 80],
    ] as $name => [$value, $max]) {
        if ($value !== null && !validate_str_len($value, 1, $max)) {
            http_response(422, ['error' => "Filtro '$name' inválido (máx $max caracteres)"]); return;
        }
    }

    if ($available !== null) {
        $validated = filter_var($available, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 1],
        ]);
        if ($validated === false) {
            http_response(422, ['error' => "Filtro 'available' deve ser 0 ou 1"]); return;
        }
        $available = $validated;
    }

    $sql = '
        SELECT u.id, u.name, u.phone,
               p.category, p.description, p.city, p.neighborhood,
               p.lat, p.lng, p.available
        FROM users u
        JOIN provider_profiles p ON p.user_id = u.id
        WHERE 1=1
    ';
    $params = [];
    if ($city)         { $sql .= ' AND LOWER(p.city) = LOWER(?)';         $params[] = $city; }
    if ($neighborhood) { $sql .= ' AND LOWER(p.neighborhood) = LOWER(?)'; $params[] = $neighborhood; }
    if ($category)     { $sql .= ' AND LOWER(p.category) = LOWER(?)';     $params[] = $category; }
    if ($available !== null) { $sql .= ' AND p.available = ?';            $params[] = $available; }

    http_response(200, ['providers' => db_fetch_all($sql, $params)]);
}
