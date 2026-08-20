<?php

declare(strict_types=1);

namespace App\Enum;

enum StatusTransfer: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    public static function statusBadgeStyles(): array
    {
        return [
            self::PENDING->value => 'warning',
            self::COMPLETED->value => 'success',
            self::FAILED->value => 'danger',
        ];
    }
}
