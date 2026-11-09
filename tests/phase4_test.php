<?php

declare(strict_types=1);

/**
 * Phase 4 expense CRUD verification script.
 * Run with: php tests/phase4_test.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/schema.php';
require_once __DIR__ . '/../src/expenses.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    echo "PASS: {$message}\n";
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
initializeSchema($pdo);

$insertUser = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (:username, :hash)');
$insertUser->execute(['username' => 'owner', 'hash' => 'hash']);
$ownerId = (int) $pdo->lastInsertId();
$insertUser->execute(['username' => 'other', 'hash' => 'hash']);
$otherId = (int) $pdo->lastInsertId();

// 1. Valid input is normalized.
$valid = validateExpenseInput([
    'amount' => '25000',
    'category' => 'Makanan',
    'expense_date' => '2026-11-09',
    'note' => '  Makan siang  ',
]);
assert_true($valid['errors'] === [], 'valid input has no validation errors');
assert_true($valid['data']['amount'] === 25000, 'amount is normalized to an integer');
assert_true($valid['data']['note'] === 'Makan siang', 'note is trimmed');
assert_true($valid['data']['expense_date'] === '2026-11-09', 'date keeps the YYYY-MM-DD format');

$emptyNote = validateExpenseInput([
    'amount' => '1000',
    'category' => 'Lainnya',
    'expense_date' => '2026-11-09',
    'note' => '   ',
]);
assert_true($emptyNote['errors'] === [] && $emptyNote['data']['note'] === null, 'blank note becomes null');

// 2. Invalid values are rejected.
$invalidAmounts = ['', '0', '-1', 'abc', '12.5', null];
foreach ($invalidAmounts as $index => $amount) {
    $result = validateExpenseInput([
        'amount' => $amount,
        'category' => 'Makanan',
        'expense_date' => '2026-11-09',
        'note' => '',
    ]);
    assert_true(isset($result['errors']['amount']), "invalid amount #{$index} is rejected");
}

$invalidCategories = ['', 'makanan', 'Makanan <script>', null];
foreach ($invalidCategories as $index => $category) {
    $result = validateExpenseInput([
        'amount' => '1000',
        'category' => $category,
        'expense_date' => '2026-11-09',
        'note' => '',
    ]);
    assert_true(isset($result['errors']['category']), "invalid category #{$index} is rejected");
}

$invalidDates = ['', '2026-02-30', '2026-13-01', '09-11-2026', '2026-1-9', 'not-a-date', null];
foreach ($invalidDates as $index => $date) {
    $result = validateExpenseInput([
        'amount' => '1000',
        'category' => 'Makanan',
        'expense_date' => $date,
        'note' => '',
    ]);
    assert_true(isset($result['errors']['expense_date']), "invalid date #{$index} is rejected");
}

$longNote = validateExpenseInput([
    'amount' => '1000',
    'category' => 'Makanan',
    'expense_date' => '2026-11-09',
    'note' => str_repeat('a', 501),
]);
assert_true(isset($longNote['errors']['note']), 'note longer than 500 bytes is rejected');

// 3. Expensive input travels through prepared statements safely.
$injectionNote = "it's a note; DROP TABLE expenses; --";
$stored = validateExpenseInput([
    'amount' => '15000',
    'category' => 'Transportasi',
    'expense_date' => '2026-11-08',
    'note' => $injectionNote,
]);
assert_true($stored['errors'] === [], 'input containing SQL characters is accepted as data');
$storedId = createExpense($pdo, $ownerId, $stored['data']);
assert_true($storedId > 0, 'createExpense() returns the new row ID');
assert_true(
    (int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn() === 1,
    'expenses table still exists after storing SQL-like text'
);

// 4. Listing is scoped to the owner and sorted newest first.
createExpense($pdo, $ownerId, [
    'amount' => 5000,
    'category' => 'Lainnya',
    'expense_date' => '2026-11-10',
    'note' => null,
]);
createExpense($pdo, $otherId, [
    'amount' => 9999,
    'category' => 'Belanja',
    'expense_date' => '2026-11-11',
    'note' => 'other user',
]);

$ownerExpenses = listExpenses($pdo, $ownerId);
assert_true(count($ownerExpenses) === 2, 'listExpenses() returns only the owner rows');
assert_true((int) $ownerExpenses[0]['amount'] === 5000, 'listExpenses() sorts newest date first');
assert_true((int) $ownerExpenses[1]['amount'] === 15000, 'older expense comes second');

// 5. Deletion enforces ownership.
assert_true(deleteExpense($pdo, $ownerId, $storedId) === true, 'owner can delete their expense');
assert_true(deleteExpense($pdo, $ownerId, $storedId) === false, 'deleting a missing expense returns false');
assert_true(deleteExpense($pdo, $otherId, 1) === false, 'another user cannot delete an expense');
assert_true(deleteExpense($pdo, $ownerId, 0) === false, 'invalid expense ID is rejected');
assert_true((int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn() === 2, 'other rows remain intact');

$rows = $pdo->query('SELECT user_id FROM expenses ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
assert_true(array_map('intval', $rows) === [$ownerId, $otherId], 'each expense is saved with the correct user_id');

echo "\nAll Phase 4 expense checks passed.\n";
