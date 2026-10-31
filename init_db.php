<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/schema.php';

try {
    $pdo = getPDO();
    initializeSchema($pdo);

    $tables = $pdo->query(
        "SELECT name FROM sqlite_master
         WHERE type = 'table' AND name IN ('users', 'expenses')
         ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);

    $indexes = $pdo->query(
        "SELECT name FROM sqlite_master
         WHERE type = 'index'
          AND name IN ('idx_expenses_user_id', 'idx_expenses_expense_date')
         ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);

    echo 'Database initialized: ' . DB_PATH . PHP_EOL;
    echo 'Tables: ' . implode(', ', $tables) . PHP_EOL;
    echo 'Indexes: ' . implode(', ', $indexes) . PHP_EOL;
    echo 'SQLite version: ' . $pdo->query('SELECT sqlite_version()')->fetchColumn() . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Database initialization failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
