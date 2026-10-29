<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

define('BASE_PATH', __DIR__);
define('DATA_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'data');
define('UPLOAD_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'uploads');
define('DB_PATH', DATA_PATH . DIRECTORY_SEPARATOR . 'database.sqlite');

define('APP_NAME', 'Personal Expense Tracker');

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_start();
}
