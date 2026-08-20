<?php

declare(strict_types=1);

namespace App\Enum;

enum TypeOperation: string
{
    case DEBIT = 'debit';
    case CREDIT = 'credit';


    /**
     * @return array<string, string>
     */
    public static function getBadgeClass(): array
    {
        return [
            self::DEBIT->value => 'danger',
            self::CREDIT->value => 'success',
        ];
    }
}
