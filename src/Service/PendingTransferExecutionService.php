<?php

declare(strict_types=1);

namespace App\Service;

interface PendingTransferExecutionService
{
    /** @return array<string, string> */
    public function execute(string $tokenOrReference): array;
}
