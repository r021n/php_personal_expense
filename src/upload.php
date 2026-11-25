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
