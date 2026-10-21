<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use App\Entity\Transfer;
use App\Enum\StatusTransfer;
use App\Enum\TypeTransfer;
use App\Repository\TransferRepository;
use App\Service\PendingTransferMonitor;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PendingTransferMonitorTest extends TestCase
{
    public function testItSelectsPendingTransfersOlderThanTheConfiguredThreshold(): void
    {
        $repository = $this->createMock(TransferRepository::class);
        $now = new DateTimeImmutable('2026-01-01 12:00:00');
        $repository->expects(self::once())
            ->method('findPendingOlderThan')
            ->with(new DateTimeImmutable('2026-01-01 11:00:00'))
            ->willReturn([(new Transfer())
                ->setReference('REF-001')
                ->setType(TypeTransfer::TRANSFER)
                ->setStatus(StatusTransfer::PENDING)
                ->setCreatedAt(new DateTimeImmutable('2026-01-01 11:00:00'))]);

        $monitor = new PendingTransferMonitor($repository, new NullLogger(), 3600);

        $overdue = $monitor->monitor($now);

        self::assertCount(1, $overdue);
        self::assertSame('pending', $overdue[0]->status->value);
        self::assertSame(TypeTransfer::TRANSFER, $overdue[0]->type);
        self::assertSame(3600, $overdue[0]->elapsedSeconds);
        self::assertSame(3600, $overdue[0]->thresholdSeconds);
    }

    public function testItReportsAnEmptyResultWithoutOverdueTransfers(): void
    {
        $repository = $this->createMock(TransferRepository::class);
        $repository->method('findPendingOlderThan')->willReturn([]);

        self::assertSame([], (new PendingTransferMonitor($repository, new NullLogger(), 3600))
            ->monitor(new DateTimeImmutable('2026-01-01 12:00:00')));
    }

    public function testThresholdMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PendingTransferMonitor(
            $this->createMock(TransferRepository::class),
            new NullLogger(),
            0,
        );
    }
}
