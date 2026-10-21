<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\StatusTransfer;
use App\Enum\TypeTransfer;
use DateTimeImmutable;

final readonly class PendingTransferInfo
{
    public function __construct(
        public string $reference,
        public TypeTransfer $type,
        public StatusTransfer $status,
        public DateTimeImmutable $createdAt,
        public int $elapsedSeconds,
        public int $thresholdSeconds,
    ) {
    }
}
