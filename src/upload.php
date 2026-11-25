<?php

declare(strict_types=1);

function receiptMaxBytes(): int
{
    return 2 * 1024 * 1024;
}

function receiptAllowedTypes(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];
}

function receiptUrl(string $filename): string
{
    return 'uploads/' . rawurlencode($filename);
}

function validateReceiptUpload(array $file): array
{
    $reject = static fn(string $message): array => [
        'ok' => false,
        'error' => $message,
        'tmp_file' => null,
        'filename' => null,
    ];

    $absent = [
        'ok' => true,
        'error' => null,
        'tmp_name' => null,
        'filename' => null,
    ];

    if (isset($file['error'])) {
        return $absent;
    }

    if (is_array($file['error'])) {
        return $reject('Permintaan upload tidak valid.');
    }

    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return $absent;
    }

    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return $reject('Ukuran nota melebihi 2 MB.');
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return $reject('Nota gagal diunggah. Coba lagi.');
    }

    $tmpName = $file['tmp_name'] ?? null;

    if (!is_string($tmpName) || $tmpName === '') {
        return $reject('Nota gagal diunggah. Coba lagi.');
    }

    $size = is_file($tmpName) ? fileSize($tmpName) : false;

    if ($size === false) {
        return $reject('Nota gagal diunggah. Coba lagi.');
    }

    if ($size < 1) {
        return $reject('Nota tidak boleh kosong.');
    }

    if ($size > receiptMaxBytes()) {
        return $reject('Ukuran nota melebihi 2 MB.');
    }
}
