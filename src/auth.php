<?php

declare(strict_types=1);

function login(PDO $pdo, string $username, string $password): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new LogicException('A session must be active before login');
    }

    if ($username === '' || $password === '' || strlen($password) > 72 || str_contains($password, "\0")) {
        return false;
    }

    $statement = $pdo->prepare(
        'SELECT id, username, password_hash FROM users WHERE username = :username LIMIT 1'
    );
    $statement->execute(['username' => $username]);
    $user = $statement->fetch();

    if (!is_array($user) || !password_verify($password, (string) $user['password_hash'])) {
        return false;
    }

    if (!session_regenerate_id(true)) {
        return false;
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];

    return true;
}

function is_logged_in(): bool
{
    return session_status() === PHP_SESSION_ACTIVE
        && isset($_SESSION['user_id'])
        && is_int($_SESSION['user_id'])
        && $_SESSION['user_id'] > 0;
}

function required_login(): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }
}

function logout(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $cookie['path'],
            'domain' => $cookie['domain'],
            'secure' => $cookie['secure'],
            'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'] ?? 'Lax',
        ]);
    }
}
