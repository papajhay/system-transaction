<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\MonitorPendingTransfersMessage;
use App\Service\PendingTransferAlertService;
use App\Service\PendingTransferAutoExecutor;
use App\Service\PendingTransferMonitor;
use App\Message\ExecuteTransferMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler]
final class MonitorPendingTransfersMessageHandler
{
    public function __construct(
        private readonly PendingTransferMonitor $monitor,
        private readonly LoggerInterface $logger,
        private readonly ?MessageBusInterface $messageBus = null,
        private readonly ?PendingTransferAutoExecutor $autoExecutor = null,
        private readonly ?PendingTransferAlertService $alertService = null,
    ) {
    }

    public function __invoke(MonitorPendingTransfersMessage $message): void
    {
        try {
            $overdue = $this->monitor->monitor();
            if ($this->messageBus !== null) {
                foreach ($overdue as $pendingTransfer) {
                    $this->messageBus->dispatch(new ExecuteTransferMessage($pendingTransfer->reference));
                }
            } elseif ($this->autoExecutor !== null) {
                // Backward-compatible fallback for direct service wiring.
                $overdue = $this->autoExecutor->execute($overdue);
            }
            $this->alertService?->notify($overdue);
        } catch (Throwable $exception) {
            // One transient failure must not stop future scheduled runs.
            $this->logger->error('Pending transfer monitoring failed; the next scheduled run will continue.', [
                'exception_class' => $exception::class,
                'exception_message' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
        }
    }
}
