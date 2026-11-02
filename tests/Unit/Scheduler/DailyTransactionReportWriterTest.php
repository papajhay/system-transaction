<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use App\Service\DailyTransactionReport;
use App\Service\DailyTransactionReportWriter;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DailyTransactionReportWriterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/transaction-report-'.bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        $file = $this->directory.'/transactions_2026-01-01.csv';
        if (is_file($file)) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testItWritesASecureCsvForAnEmptyReport(): void
    {
        $path = (new DailyTransactionReportWriter('/tmp', $this->directory, 'UTC'))->write(new DailyTransactionReport(
            new DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')),
            new DateTimeImmutable('2026-01-02 00:00:00', new \DateTimeZone('UTC')),
            new DateTimeImmutable('2026-01-01 02:00:00', new \DateTimeZone('UTC')),
            [],
        ));

        self::assertSame($this->directory.'/transactions_2026-01-01.csv', $path);
        self::assertSame(0600, fileperms($path) & 0777);
        self::assertStringContainsString('period_start,period_end,generated_at,type,currency,count,total_amount,total_fees', file_get_contents($path));
    }

    public function testItEscapesSpecialCharactersQuotesAndDelimiters(): void
    {
        $path = (new DailyTransactionReportWriter('/tmp', $this->directory, 'UTC'))->write(new DailyTransactionReport(
            new DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')),
            new DateTimeImmutable('2026-01-02 00:00:00', new \DateTimeZone('UTC')),
            new DateTimeImmutable('2026-01-01 02:00:00', new \DateTimeZone('UTC')),
            [[
                'type' => 'transfer, "priority"',
                'currency' => "EUR\nUSD",
                'count' => 2,
                'total_amount' => '10.50',
                'total_fees' => '0.25',
            ]],
        ));

        $handle = fopen($path, 'rb');
        self::assertNotFalse($handle);
        $header = fgetcsv($handle, escape: '\\');
        $row = fgetcsv($handle, escape: '\\');
        fclose($handle);

        self::assertSame(
            ['period_start', 'period_end', 'generated_at', 'type', 'currency', 'count', 'total_amount', 'total_fees'],
            $header,
        );
        self::assertSame([
            '2026-01-01T00:00:00+00:00',
            '2026-01-02T00:00:00+00:00',
            '2026-01-01T02:00:00+00:00',
            'transfer, "priority"',
            "EUR\nUSD",
            '2',
            '10.50',
            '0.25',
        ], $row);
    }
}
