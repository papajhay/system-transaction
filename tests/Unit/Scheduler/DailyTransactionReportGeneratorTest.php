<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use App\Entity\Currency;
use App\Entity\Transfer;
use App\Enum\StatusTransfer;
use App\Enum\TypeTransfer;
use App\Repository\TransferRepository;
use App\Service\DailyTransactionReportGenerator;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DailyTransactionReportGeneratorTest extends TestCase
{
    public function testItAggregatesCompletedTransfersByTypeAndCurrencyUsingPersistedFees(): void
    {
        $repository = $this->createMock(TransferRepository::class);
        $repository->expects(self::once())->method('findCompletedBetween')
            ->with(new DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')), new DateTimeImmutable('2026-01-02 00:00:00', new \DateTimeZone('UTC')))
            ->willReturn([
                $this->transfer(TypeTransfer::DEPOSIT, '10.00', 1.25, 'USD'),
                $this->transfer(TypeTransfer::DEPOSIT, '5.00', 0.75, 'USD'),
                $this->transfer(TypeTransfer::WITHDRAWAL, '7.50', 2.00, 'EUR'),
            ]);

        $report = (new DailyTransactionReportGenerator($repository, 'UTC'))
            ->generate(new DateTimeImmutable('2026-01-01 12:00:00', new \DateTimeZone('UTC')));

        self::assertSame([
            ['type' => 'deposit', 'currency' => 'USD', 'count' => 2, 'total_amount' => '15.00', 'total_fees' => '2.00'],
            ['type' => 'withdrawal', 'currency' => 'EUR', 'count' => 1, 'total_amount' => '7.50', 'total_fees' => '2.00'],
        ], $report->rows);
    }

    public function testItSupportsAnEmptyReportingPeriod(): void
    {
        $repository = $this->createMock(TransferRepository::class);
        $repository->method('findCompletedBetween')->willReturn([]);

        $report = (new DailyTransactionReportGenerator($repository, 'Europe/Paris'))
            ->generate(new DateTimeImmutable('2026-01-02 12:00:00', new \DateTimeZone('UTC')));

        self::assertSame([], $report->rows);
        self::assertSame('2026-01-02T00:00:00+01:00', $report->periodStart->format(DATE_ATOM));
        self::assertSame('2026-01-03T00:00:00+01:00', $report->periodEnd->format(DATE_ATOM));
    }

    private function transfer(TypeTransfer $type, string $amount, float $fee, string $currencyCode): Transfer
    {
        $currency = (new Currency())->setCode($currencyCode)->setName($currencyCode)->setSymbol($currencyCode);
        $time = new DateTimeImmutable('2026-01-01 12:00:00', new \DateTimeZone('UTC'));

        return (new Transfer())
            ->setToken('TOKEN-'.$type->value.'-'.$currencyCode.'-'.$amount)
            ->setReference('REF-'.$type->value.'-'.$currencyCode.'-'.$amount)
            ->setAmount($amount)
            ->setCurrency($currency)
            ->setReceivedAmount($amount)
            ->setReceivedCurrency($currency)
            ->setExchangeRate('1.0000000000')
            ->setType($type)
            ->setStatus(StatusTransfer::COMPLETED)
            ->setFeeAmount($fee)
            ->setProcessedAt($time)
            ->setCreatedAt($time)
            ->setUpdatedAt($time);
    }
}
