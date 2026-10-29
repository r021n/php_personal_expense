<?php

declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/src/helpers.php";

$title = 'Fase 1 - Setup Berhasil';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> - <?= e(APP_NAME) ?></title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            max-width: 720px;
            margin: 4rem auto;
            padding: 0 1rem;
        }

        .ok {
            color: #15803d;
        }

        li {
            margin-bottom: .35rem;
        }
    </style>
</head>

<body>
    <h1><?= e(APP_NAME) ?></h1>
    <p class="ok">Fase 1 selesai. Konfigurasi dan helper berhasil dimuat.</p>
    <ul>
        <li>Timezone: <?= e(date_default_timezone_get()) ?></li>
        <li>Database: <?= e(DB_PATH) ?></li>
        <li>Upload: <?= e(UPLOAD_PATH) ?></li>
        <li>Fileinfo: <?= extension_loaded('fileinfo') ? 'aktif' : 'tidak aktif' ?></li>
    </ul>
    <form method="post" action="#">
        <?= csrf_field() ?>
        <button type="submit">Uji CSRF field</button>
    </form>
</body>

</html>