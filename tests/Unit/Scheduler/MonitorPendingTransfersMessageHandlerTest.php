<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use App\Message\MonitorPendingTransfersMessage;
use App\MessageHandler\MonitorPendingTransfersMessageHandler;
use App\Service\PendingTransferMonitor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

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
}
