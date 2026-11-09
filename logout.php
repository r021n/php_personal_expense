<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/helpers.php';
require_once __DIR__ . '/src/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Logout hanya menerima permintaan POST.');
}

$token = $_POST['_csrf_token'] ?? null;
if (!verify_csrf(is_string($token) ? $token : null)) {
    http_response_code(403);
    exit('Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.');
}

logout();
redirect('login.php');
