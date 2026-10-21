<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

final readonly class DailyTransactionReport
{
    /**
     * @param list<array{type: string, currency: string, count: int, total_amount: string, total_fees: string}> $rows
     */
    public function __construct(
        public DateTimeImmutable $periodStart,
        public DateTimeImmutable $periodEnd,
        public DateTimeImmutable $generatedAt,
        public array $rows,
    ) {
    }
}
