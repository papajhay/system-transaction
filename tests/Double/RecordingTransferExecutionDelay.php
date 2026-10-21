<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Service\TransferExecutionDelay;

final class RecordingTransferExecutionDelay implements TransferExecutionDelay
{
    /** @var list<int> */
    public array $delays = [];

    public function wait(int $seconds): void
    {
        $this->delays[] = $seconds;
    }
}
