<?php

declare(strict_types=1);

namespace App\Enum;

enum TypeTransfer: string
{
    case DEPOSIT = 'deposit';
    case WITHDRAWAL = 'withdrawal';
    case TRANSFER = 'transfer';
}
