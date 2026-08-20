<?php

declare(strict_types=1);

namespace App\Enum;

enum StatusAccount: string
{
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
    case CLOSED = 'closed';

    /**
     * @return array<string, string>
     */
    public static function badgeStyles(): array
    {
        return [
            self::ACTIVE->value => 'success',
            self::SUSPENDED->value => 'warning',
            self::CLOSED->value => 'danger',
        ];
    }
}
