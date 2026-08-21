<?php

declare(strict_types=1);

namespace App\Enum;

enum TypeFee: string
{
    case FEE_CHARGED = 'fee charged';
    case FREE_CHARGED = 'free charged';
}
