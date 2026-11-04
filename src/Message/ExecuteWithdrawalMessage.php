<?php

declare(strict_types=1);

namespace App\Message;

final readonly class ExecuteWithdrawalMessage
{
    public function __construct(public string $reference)
    {
    }
}
