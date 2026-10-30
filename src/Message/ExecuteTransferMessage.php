<?php

declare(strict_types=1);

namespace App\Message;

final readonly class ExecuteTransferMessage
{
    public function __construct(public string $reference)
    {
    }
}
