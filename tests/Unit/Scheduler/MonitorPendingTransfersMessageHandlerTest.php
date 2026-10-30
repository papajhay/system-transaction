<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use App\Message\MonitorPendingTransfersMessage;
use App\Message\ExecuteTransferMessage;
use App\MessageHandler\MonitorPendingTransfersMessageHandler;
use App\Dto\PendingTransferInfo;
use App\Enum\StatusTransfer;
use App\Enum\TypeTransfer;
use App\Service\PendingTransferMonitor;
use Symfony\Component\Messenger\MessageBusInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use DateTimeImmutable;

final class MonitorPendingTransfersMessageHandlerTest extends TestCase
{
    public function testHandlerDelegatesToMonitor(): void
    {
        $monitor = $this->createMock(PendingTransferMonitor::class);
        $monitor->expects(self::once())->method('monitor');

        (new MonitorPendingTransfersMessageHandler($monitor, new NullLogger()))(
            new MonitorPendingTransfersMessage(),
        );
    }

    public function testHandlerContainsMonitoringFailures(): void
    {
        $monitor = $this->createMock(PendingTransferMonitor::class);
        $monitor->method('monitor')->willThrowException(new \RuntimeException('database unavailable'));

        (new MonitorPendingTransfersMessageHandler($monitor, new NullLogger()))(
            new MonitorPendingTransfersMessage(),
        );

        self::assertTrue(true);
    }

    public function testHandlerDispatchesOverdueTransfersToTheExecutionBus(): void
    {
        $monitor = $this->createMock(PendingTransferMonitor::class);
        $monitor->expects(self::once())->method('monitor')->willReturn([
            new PendingTransferInfo(
                'TRF-1', TypeTransfer::TRANSFER, StatusTransfer::PENDING,
                new DateTimeImmutable('-61 seconds'), 61, 60,
            ),
        ]);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (object $message): bool =>
                $message instanceof ExecuteTransferMessage && $message->reference === 'TRF-1'));

        (new MonitorPendingTransfersMessageHandler($monitor, new NullLogger(), $bus))(
            new MonitorPendingTransfersMessage(),
        );
    }
}
