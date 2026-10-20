<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use App\Entity\Transfer;
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
            ->willReturn([new Transfer()]);

        $monitor = new PendingTransferMonitor($repository, new NullLogger(), 3600);

        self::assertSame(1, $monitor->monitor($now));
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
