<?php

declare(strict_types=1);

namespace App\Enum;

enum TypeTransfer: string
{
    case DEPOSIT = 'deposit';
    case WITHDRAWAL = 'withdrawal';
    case TRANSFER = 'transfer';

    public static function typeBadgeStyles(): array
    {
        return [
            self::DEPOSIT->value => 'success',
            self::WITHDRAWAL->value => 'danger',
            self::TRANSFER->value => 'primary',
        ];
    }
}
