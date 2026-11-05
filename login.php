<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/helpers.php';
require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

if (is_logged_in()) {
    redirect('index.php');
}

$error = null;
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedUsername = $_POST['username'];
    $postedPassword = $_POST['password'];
    $username = is_string($postedUsername) ? trim($postedUsername) : '';

    if (!verify_csrf(is_string($_POST['_csrf_token'] ?? null) ? $_POST['_csrf_token'] : null)) {
        $error = 'Permintaan tidak valid, Muat ulang halaman lalu coba lagi.';
    } elseif (!is_string($postedUsername) || !is_string($postedPassword) || $username === '' || $postedPassword === '') {
        $error = 'Username atau password salah';
    } elseif (login(getPDO(), $username, $postedPassword)) {
        redirect('index.php');
    } else {
        $error = 'Username atau password salah';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= e(APP_NAME) ?></title>
</head>

<body>
    <main>
        <h1>Login Personal Expense Tracker</h1>

        <?php if ($error !== null): ?>
            <p role="alert"><?= e($error) ?></p>
        <?php endif; ?>

        <form method="post" action="login.php">
            <?= csrf_field() ?>

            <label for="username">Username</label>
            <input id="username" name="username" type="text" value="<?= e($username) ?>" autocomplete="username" required>

            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>

            <button type="submit">Masuk</button>
        </form>
    </main>
</body>

</html>