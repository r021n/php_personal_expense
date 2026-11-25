<?php

declare(strict_types=1);

/**
 * Phase 5 receipt upload verification script.
 * Run with: php tests/phase5_test.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/schema.php';
require_once __DIR__ . '/../src/expenses.php';
require_once __DIR__ . '/../src/upload.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    echo "PASS: {$message}\n";
}

$fixtureDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phase5_' . bin2hex(random_bytes(4));
mkdir($fixtureDir, 0775, true);

$pngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
$jpgBase64 = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD3+iiigD//2Q==';

$pngFile = $fixtureDir . DIRECTORY_SEPARATOR . 'real.png';
$jpgFile = $fixtureDir . DIRECTORY_SEPARATOR . 'real.jpg';
$fakeFile = $fixtureDir . DIRECTORY_SEPARATOR . 'fake.jpg';
$emptyFile = $fixtureDir . DIRECTORY_SEPARATOR . 'empty.png';
$bigFile = $fixtureDir . DIRECTORY_SEPARATOR . 'big.png';

file_put_contents($pngFile, base64_decode($pngBase64, true));
file_put_contents($jpgFile, base64_decode($jpgBase64, true));
file_put_contents($fakeFile, "<?php echo 'not an image';");
file_put_contents($emptyFile, '');
file_put_contents($bigFile, str_repeat('A', receiptMaxBytes() + 1));

$asUpload = static fn (string $path): array => [
    'name' => basename($path),
    'type' => 'application/octet-stream',
    'tmp_name' => $path,
    'error' => UPLOAD_ERR_OK,
    'size' => (int) filesize($path),
];

// 1. Constants and allow-list.
assert_true(receiptMaxBytes() === 2 * 1024 * 1024, 'receipt limit is exactly 2 MB');
assert_true(
    array_keys(receiptAllowedTypes()) === ['image/jpeg', 'image/png'],
    'only image/jpeg and image/png are allowed'
);

// 2. Optional upload: no file at all is not an error.
$missing = validateReceiptUpload([]);
assert_true($missing['ok'] && $missing['error'] === null && $missing['tmp_name'] === null, 'absent $_FILES entry is treated as no file');

$noFile = validateReceiptUpload(['error' => UPLOAD_ERR_NO_FILE]);
assert_true($noFile['ok'] && $noFile['tmp_name'] === null, 'UPLOAD_ERR_NO_FILE is treated as no file');

// 3. Upload error codes are mapped to messages.
$corrupted = validateReceiptUpload(['error' => ['0', '4']]);
assert_true(!$corrupted['ok'] && $corrupted['error'] === 'Permintaan upload tidak valid.', 'array error (multi-file attack) is rejected');

$iniSize = validateReceiptUpload(['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => $pngFile]);
assert_true(!$iniSize['ok'] && $iniSize['error'] === 'Ukuran nota melebihi 2 MB.', 'UPLOAD_ERR_INI_SIZE reports the 2 MB limit');

$formSize = validateReceiptUpload(['error' => UPLOAD_ERR_FORM_SIZE, 'tmp_name' => $pngFile]);
assert_true(!$formSize['ok'] && $formSize['error'] === 'Ukuran nota melebihi 2 MB.', 'UPLOAD_ERR_FORM_SIZE reports the 2 MB limit');

$partial = validateReceiptUpload(['error' => UPLOAD_ERR_PARTIAL, 'tmp_name' => $pngFile]);
assert_true(!$partial['ok'] && $partial['error'] === 'Nota gagal diunggah. Coba lagi.', 'other upload errors are rejected');

// 4. Real images pass validation and get a random name.
$png = validateReceiptUpload($asUpload($pngFile));
assert_true($png['ok'] && $png['error'] === null, 'a real PNG passes validation');
assert_true(
    is_string($png['filename']) && preg_match('/^[0-9a-f]{32}\.png$/', $png['filename']) === 1,
    'PNG gets a 32-char hex name with the .png extension'
);

$jpg = validateReceiptUpload($asUpload($jpgFile));
assert_true($jpg['ok'], 'a real JPEG passes validation');
assert_true(
    is_string($jpg['filename']) && preg_match('/^[0-9a-f]{32}\.jpg$/', $jpg['filename']) === 1,
    'JPEG gets a 32-char hex name with the .jpg extension'
);

assert_true($png['filename'] !== $jpg['filename'], 'generated names are random, not derived from the original name');

// 5. Negative cases.
$fake = validateReceiptUpload($asUpload($fakeFile));
assert_true(!$fake['ok'] && $fake['error'] === 'Nota harus berupa file JPG atau PNG.', 'a .jpg file whose content is PHP text is rejected');

$empty = validateReceiptUpload($asUpload($emptyFile));
assert_true(!$empty['ok'] && $empty['error'] === 'Nota tidak boleh kosong.', 'an empty file is rejected');

$big = validateReceiptUpload($asUpload($bigFile));
assert_true(!$big['ok'] && $big['error'] === 'Ukuran nota melebihi 2 MB.', 'a file larger than 2 MB is rejected');

$noTmp = validateReceiptUpload(['error' => UPLOAD_ERR_OK, 'tmp_name' => '']);
assert_true(!$noTmp['ok'], 'a missing tmp_name is rejected');

// 6. handleReceiptUpload only moves real HTTP uploads.
$before = array_diff(scandir(UPLOAD_PATH) ?: [], ['.', '..']);
set_error_handler(static fn (): bool => true);
$handled = handleReceiptUpload($asUpload($pngFile));
restore_error_handler();
$after = array_diff(scandir(UPLOAD_PATH) ?: [], ['.', '..']);

assert_true($handled['path'] === null && $handled['error'] !== null, 'CLI call cannot move a file, so it reports an error');
assert_true($before === $after, 'no file is written to uploads/ when the move fails');

$none = handleReceiptUpload([]);
assert_true($none['path'] === null && $none['error'] === null, 'handleReceiptUpload() with no file returns no path and no error');

// 7. deleteReceiptFile() only removes plain file names inside uploads/.
$staged = UPLOAD_PATH . DIRECTORY_SEPARATOR . 'phase5-fixture.jpg';
file_put_contents($staged, base64_decode($jpgBase64, true));
assert_true(deleteReceiptFile('phase5-fixture.jpg') === true, 'a stored receipt can be deleted');
assert_true(!is_file($staged), 'the receipt file is really gone');
assert_true(deleteReceiptFile('phase5-fixture.jpg') === false, 'deleting a missing receipt returns false');
assert_true(deleteReceiptFile('../config.php') === false, 'path traversal is rejected');
assert_true(deleteReceiptFile('sub' . DIRECTORY_SEPARATOR . 'file.jpg') === false, 'nested paths are rejected');
assert_true(deleteReceiptFile('') === false, 'an empty name is rejected');

// 8. receipt_path travels through the expense functions.
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

$expense = [
    'amount' => 25000,
    'category' => 'Makanan',
    'expense_date' => '2026-11-12',
    'note' => 'Makan siang',
];

$withReceipt = createExpense($pdo, $ownerId, $expense, 'abc123.jpg');
$withoutReceipt = createExpense($pdo, $ownerId, $expense);

$rows = listExpenses($pdo, $ownerId);
$byId = [];
foreach ($rows as $row) {
    $byId[(int) $row['id']] = $row;
}

assert_true($byId[$withReceipt]['receipt_path'] === 'abc123.jpg', 'listExpenses() returns the stored receipt path');
assert_true($byId[$withoutReceipt]['receipt_path'] === null, 'an expense without a photo keeps a null receipt path');
assert_true(
    getExpenseReceiptPath($pdo, $ownerId, $withReceipt) === 'abc123.jpg',
    'getExpenseReceiptPath() returns the owner receipt'
);
assert_true(
    getExpenseReceiptPath($pdo, $otherId, $withReceipt) === null,
    'another user cannot read the receipt path'
);
assert_true(getExpenseReceiptPath($pdo, $ownerId, 0) === null, 'an invalid expense ID returns null');

// 9. Legacy call signature still works.
$legacy = createExpense($pdo, $ownerId, $expense);
assert_true($legacy > 0 && getExpenseReceiptPath($pdo, $ownerId, $legacy) === null, 'createExpense() without a receipt still works');

array_map('unlink', glob($fixtureDir . DIRECTORY_SEPARATOR . '*') ?: []);
rmdir($fixtureDir);

echo "\nAll Phase 5 receipt upload checks passed.\n";
