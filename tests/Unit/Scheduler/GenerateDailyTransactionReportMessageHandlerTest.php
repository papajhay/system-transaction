<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use App\Message\GenerateDailyTransactionReportMessage;
use App\Service\DailyTransactionReport;
use App\Service\DailyTransactionReportGenerator;
use App\Service\DailyTransactionReportWriter;
use App\MessageHandler\GenerateDailyTransactionReportMessageHandler;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GenerateDailyTransactionReportMessageHandlerTest extends TestCase
{
    public function testHandlerGeneratesAndWritesThePreviousDayReport(): void
    {
        $generator = $this->createMock(DailyTransactionReportGenerator::class);
        $writer = $this->createMock(DailyTransactionReportWriter::class);
        $report = new DailyTransactionReport(
            new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-01-02 00:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-01-02 02:00:00', new DateTimeZone('UTC')),
            [],
        );
        $generator->expects(self::once())->method('generate')->with(self::isInstanceOf(DateTimeImmutable::class))->willReturn($report);
        $writer->expects(self::once())->method('write')->with($report)->willReturn('/secure/reports/transactions_2026-01-01.csv');

        (new GenerateDailyTransactionReportMessageHandler($generator, $writer, new NullLogger(), 'UTC'))(
            new GenerateDailyTransactionReportMessage(),
        );
    }
}
