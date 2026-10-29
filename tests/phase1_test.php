<?php
declare(strict_types=1);

/**
 * Phase 1 verification script.
 * Run with: php tests/phase1_test.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/helpers.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    echo "PASS: {$message}\n";
}

// 1. Configuration constants
assert_true(defined('DB_PATH'), 'DB_PATH defined');
assert_true(str_ends_with(DB_PATH, 'database.sqlite'), 'DB_PATH points to database.sqlite');
assert_true(defined('UPLOAD_PATH'), 'UPLOAD_PATH defined');
assert_true(defined('APP_NAME'), 'APP_NAME defined');

// 2. fileinfo extension
assert_true(extension_loaded('fileinfo'), 'fileinfo extension loaded');
assert_true(function_exists('finfo_file'), 'finfo_file exists');

// 3. HTML escaping helper
assert_true(e('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;', 'e() escapes HTML');
assert_true(e(null) === '', 'e() handles null');
assert_true(e("O'Reilly") === 'O&#039;Reilly', 'e() escapes single quotes');

// 4. Flash + CSRF helpers (manual session for CLI)
$_SESSION = [];
flash('success', 'Saved');
assert_true(flash('success') === 'Saved', 'flash() stores and returns');
assert_true(flash('success') === null, 'flash() consumes message');

$token = csrf_token();
assert_true(strlen($token) === 64, 'csrf_token() returns 64 hex chars');
assert_true(csrf_token() === $token, 'csrf_token() is stable within session');
assert_true(verify_csrf($token) === true, 'verify_csrf() accepts valid token');
assert_true(verify_csrf('wrong') === false, 'verify_csrf() rejects invalid token');
assert_true(verify_csrf(null) === false, 'verify_csrf() rejects null');
assert_true(str_contains(csrf_field(), $token), 'csrf_field() embeds the token');

// 5. Writable folders
foreach ([UPLOAD_PATH, DATA_PATH] as $dir) {
    $file = $dir . DIRECTORY_SEPARATOR . '.write_test';
    $ok = @file_put_contents($file, 'ok') !== false;
    assert_true($ok, "writable: {$dir}");

    if ($ok) {
        unlink($file);
    }
}

echo "\nAll Phase 1 checks passed.\n";
