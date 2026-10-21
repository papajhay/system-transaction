<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transfer;
use App\Repository\TransferRepository;
use DateTimeImmutable;
use DateTimeZone;

class DailyTransactionReportGenerator
{
    private readonly DateTimeZone $timezone;

    public function __construct(
        private readonly TransferRepository $transferRepository,
        string $timezone,
    ) {
        $this->timezone = new DateTimeZone($timezone);
    }

    public function generate(DateTimeImmutable $day): DailyTransactionReport
    {
        $periodStart = $day->setTimezone($this->timezone)->setTime(0, 0, 0);
        $periodEnd = $periodStart->modify('+1 day');
        $totals = [];

        foreach ($this->transferRepository->findCompletedBetween($periodStart, $periodEnd) as $transfer) {
            $currency = $transfer->getCurrency()?->getCode();
            if ($currency === null) {
                continue;
            }

            $key = $transfer->getType()->value.'|'.$currency;
            $totals[$key] ??= [
                'type' => $transfer->getType()->value,
                'currency' => $currency,
                'count' => 0,
                'amount_cents' => 0,
                'fee_cents' => 0,
            ];
            ++$totals[$key]['count'];
            $totals[$key]['amount_cents'] += $this->cents($transfer->getAmount());
            $totals[$key]['fee_cents'] += $this->cents((string) $transfer->getFeeAmount());
        }

        $rows = array_map(fn (array $total): array => [
            'type' => $total['type'],
            'currency' => $total['currency'],
            'count' => $total['count'],
            'total_amount' => $this->money($total['amount_cents']),
            'total_fees' => $this->money($total['fee_cents']),
        ], array_values($totals));

        usort($rows, static fn (array $a, array $b): int => [$a['type'], $a['currency']] <=> [$b['type'], $b['currency']]);

        return new DailyTransactionReport($periodStart, $periodEnd, new DateTimeImmutable('now', $this->timezone), $rows);
    }

    private function cents(string $amount): int
    {
        if (!preg_match('/^\s*(\d+)(?:\.(\d{1,6}))?\s*$/', $amount, $matches)) {
            return 0;
        }

        $fraction = str_pad($matches[2] ?? '', 3, '0');
        $cents = (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            ++$cents;
        }

        return ((int) $matches[1] * 100) + $cents;
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
