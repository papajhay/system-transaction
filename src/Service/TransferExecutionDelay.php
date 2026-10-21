<?php

declare(strict_types=1);

namespace App\Service;

interface TransferExecutionDelay
{
    public function wait(int $seconds): void;
}
