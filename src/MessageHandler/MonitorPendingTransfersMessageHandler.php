<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\MonitorPendingTransfersMessage;
use App\Service\PendingTransferAlertService;
use App\Service\PendingTransferAutoExecutor;
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
        private readonly ?PendingTransferAutoExecutor $autoExecutor = null,
        private readonly ?PendingTransferAlertService $alertService = null,
    ) {
    }

    public function __invoke(MonitorPendingTransfersMessage $message): void
    {
        try {
            $overdue = $this->monitor->monitor();
            $failed = $this->autoExecutor?->execute($overdue) ?? $overdue;
            $this->alertService?->notify($failed);
        } catch (Throwable $exception) {
            // One transient failure must not stop future scheduled runs.
            $this->logger->error('Pending transfer monitoring failed; the next scheduled run will continue.', [
                'exception_class' => $exception::class,
                'exception_message' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
        }
    }
}
