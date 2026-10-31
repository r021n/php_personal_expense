<?php

declare(strict_types=1);

function initializeSchema(PDO $pdo): void {
    $statements = [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS users (
            id              INTEGER     PRIMARY KEY,
            username        TEXT        NOT NULL UNIQUE,
            password_hash   TEXT        NOT NULL,
            created_at      TEXT        NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS expenses (
            id              INTEGER PRIMARY KEY,
            user_id         INTEGER NOT NULL,
            amount          INTEGER NOT NULL CHECK (amount > 0),
            category        TEXT    NOT NULL,
            expense_date    TEXT    NOT NULL,
            note            TEXT,
            receipt_path    TEXT,
            created_at      TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY  (user_id)  REFERENCES users (id) ON DELETE CASCADE
        )
        SQL,
        'CREATE INDEX IF NOT EXISTS idx_expenses_user_id ON expenses (user_id)',
        'CREATE INDEX IF NOT EXISTS idx_expenses_expense_date ON expenses (expense_date)'
    ];

    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }
}