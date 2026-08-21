<?php

declare(strict_types=1);

namespace App\Enum;

enum Role: string
{
    case USER = 'user';
    case ADMIN = 'admin';
}
