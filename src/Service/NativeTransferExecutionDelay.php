<?php

declare(strict_types=1);

namespace App\Service;

final class NativeTransferExecutionDelay implements TransferExecutionDelay
{
    public function wait(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }
}
