<?php

declare(strict_types=1);

/**
 * Phase 2 verification script.
 * Run with: php tests/phase2_test.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/schema.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    echo "PASS: {$message}\n";
}

// 1. Real connection uses the configured SQLite file.
$pdo = getPDO();
assert_true($pdo instanceof PDO, 'getPDO() returns a PDO instance');
assert_true($pdo->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION, 'PDO error mode is exception');
assert_true($pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE) === PDO::FETCH_ASSOC, 'PDO default fetch mode is associative');
assert_true((int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() === 1, 'foreign keys are enabled');
assert_true(getPDO() === $pdo, 'getPDO() reuses the same connection');

// 2. Schema creation is idempotent and preserves data.
$memory = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$memory->exec('PRAGMA foreign_keys = ON');

initializeSchema($memory);
initializeSchema($memory);

$tables = $memory->query(
    "SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('users', 'expenses') ORDER BY name"
)->fetchAll(PDO::FETCH_COLUMN);
assert_true($tables === ['expenses', 'users'], 'users and expenses tables exist');

$indexes = $memory->query(
    "SELECT name FROM sqlite_master WHERE type = 'index' AND name IN ('idx_expenses_user_id', 'idx_expenses_expense_date') ORDER BY name"
)->fetchAll(PDO::FETCH_COLUMN);
assert_true($indexes === ['idx_expenses_expense_date', 'idx_expenses_user_id'], 'expense indexes exist');

$memory->exec("INSERT INTO users (username, password_hash) VALUES ('tester', 'hash')");
$userId = (int) $memory->lastInsertId();
$memory->exec("INSERT INTO expenses (user_id, amount, category, expense_date, note) VALUES ({$userId}, 25000, 'Makanan', '2026-10-29', 'Makan siang')");

initializeSchema($memory);
assert_true((int) $memory->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1, 're-running schema keeps users');
assert_true((int) $memory->query('SELECT COUNT(*) FROM expenses')->fetchColumn() === 1, 're-running schema keeps expenses');

// 3. Constraints are enforced.
$rejectedNegative = false;
try {
    $memory->exec("INSERT INTO expenses (user_id, amount, category, expense_date) VALUES ({$userId}, 0, 'Makanan', '2026-10-29')");
} catch (PDOException) {
    $rejectedNegative = true;
}
assert_true($rejectedNegative, 'amount must be greater than zero');

$rejectedOrphan = false;
try {
    $memory->exec("INSERT INTO expenses (user_id, amount, category, expense_date) VALUES (999999, 10000, 'Lainnya', '2026-10-29')");
} catch (PDOException) {
    $rejectedOrphan = true;
}
assert_true($rejectedOrphan, 'expense must reference an existing user');

$memory->exec("DELETE FROM users WHERE id = {$userId}");
assert_true((int) $memory->query('SELECT COUNT(*) FROM expenses')->fetchColumn() === 0, 'deleting a user cascades to expenses');

// 4. Prepared statements work without emulation.
$statement = $memory->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
$statement->execute(['username' => 'tester']);
assert_true((int) $statement->fetchColumn() === 0, 'prepared statement executes correctly');

echo "\nAll Phase 2 checks passed.\n";
