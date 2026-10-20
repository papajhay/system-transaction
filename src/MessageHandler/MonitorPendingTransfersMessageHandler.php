<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\MonitorPendingTransfersMessage;
use App\Service\PendingTransferMonitor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler]
final class MonitorPendingTransfersMessageHandler
{
    public function __construct(
        private readonly PendingTransferMonitor $monitor,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(MonitorPendingTransfersMessage $message): void
    {
        try {
            $this->monitor->monitor();
        } catch (Throwable $exception) {
            // Monitoring is read-only; one transient failure must not stop future runs.
            $this->logger->error('Pending transfer monitoring failed; the next scheduled run will continue.', [
                'exception_class' => $exception::class,
            ]);
        }
    }
}
