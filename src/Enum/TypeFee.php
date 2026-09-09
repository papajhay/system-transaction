<?php

declare(strict_types=1);

namespace App\Enum;

enum TypeFee: string
{
    case FEE_CHARGED_FIXED = 'fee charged_fixed';
    case FEE_CHARGED_RATE = 'fee charged_rate';
    case FREE_CHARGED = 'free charged';
}
