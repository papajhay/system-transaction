<?php

declare(strict_types=1);

namespace App\Enum;

enum TypeAccount: string
{
    case USER = 'user';
    case SYSTEM = 'system';
}
