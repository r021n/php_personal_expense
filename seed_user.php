<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/db.php';

$username = trim((string) (getenv('APP_SEED_USERNAME') ?: 'admin'));
$password = getenv('APP_SEED_PASSWORD');

if (!is_string($password) || strlen($password)  < 8 || strlen($password) > 72 || str_contains($password, "\0")) {
    fwrite(STDERR, "Seed gagal: set APP_SEED_PASSWORD dengan password 8-72 bytw tanpa karakter NUL.\n");
    exit(1);
}

try {
    $pdo = getPDO();
    $pdo->beginTransaction();

    $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($userCount > 0) {
        throw new RuntimeException('Tabel users sudah berisi akun; seed tidak mengubah data yang sudah ada');
    }

    $statement = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (:username, :password_hash)');
    $statement->execute([
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);

    $pdo->commit();
    fwrite(STDOUT, "Akun awal berhasil dibuat: {$username}" . PHP_EOL);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, 'Seed gagal: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
