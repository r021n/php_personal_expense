<?php

declare(strict_types=1);

/**
 * Phase 3 authentication verification script.
 * Run with: php tests/phase3_test.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/schema.php';
require_once __DIR__ . '/../src/auth.php';

session_save_path(sys_get_temp_dir());
if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
    fwrite(STDERR, "FAIL: test session could not be started\n");
    exit(1);
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
initializeSchema($pdo);

$password = 'correct-test-password-2026';
$insert = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (:username, :hash)');
$insert->execute([
    'username' => 'phase3_tester',
    'hash' => password_hash($password, PASSWORD_DEFAULT),
]);

$checks = [];
$checks['bad password is rejected'] = login($pdo, 'phase3_tester', 'wrong-password') === false;
$checks['failed login does not authenticate the session'] = is_logged_in() === false;

$_SESSION['_csrf_token'] = 'phase3-csrf-test-token';
$previousSessionId = session_id();
$checks['valid credentials are accepted'] = login($pdo, 'phase3_tester', $password) === true;
$checks['login regenerates the session ID'] = session_id() !== $previousSessionId;
$checks['authenticated user ID is stored as an integer'] = ($_SESSION['user_id'] ?? null) === 1;
$checks['authenticated username is stored'] = ($_SESSION['username'] ?? null) === 'phase3_tester';
$checks['CSRF session data survives session regeneration'] = ($_SESSION['_csrf_token'] ?? null) === 'phase3-csrf-test-token';
$checks['authenticated session is recognized'] = is_logged_in();

logout();
$checks['logout clears authenticated session data'] = is_logged_in() === false
    && !isset($_SESSION['user_id'])
    && !isset($_SESSION['username']);

foreach ($checks as $description => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$description}\n");
        exit(1);
    }
}

foreach ($checks as $description => $_passed) {
    echo "PASS: {$description}\n";
}

echo "\nAll Phase 3 authentication checks passed.\n";
