<?php

declare(strict_types=1);

function db_connect(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, $options);

    if (DB_DRIVER === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    return $pdo;
}

function db_run(string $sql, array $params = []): \PDOStatement {
    $stmt = db_connect()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function db_fetch(string $sql, array $params = []): ?array {
    return db_run($sql, $params)->fetch() ?: null;
}

function db_fetch_all(string $sql, array $params = []): array {
    return db_run($sql, $params)->fetchAll();
}

function db_last_id(): string {
    return db_connect()->lastInsertId();
}
