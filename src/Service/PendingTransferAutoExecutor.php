<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\PendingTransferInfo;
use App\Entity\Transfer;
use App\Enum\TypeTransfer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Throwable;

final class PendingTransferAutoExecutor
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly PendingTransferExecutionService $depositExecutor,
        private readonly PendingTransferExecutionService $withdrawalExecutor,
        private readonly PendingTransferExecutionService $transferExecutor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<PendingTransferInfo> $pendingTransfers
     * @return list<PendingTransferInfo> transfers that could not be executed
     */
    public function execute(array $pendingTransfers): array
    {
        $failed = [];
        foreach ($pendingTransfers as $pendingTransfer) {
            try {
                // Resolve the repository for every item. ServiceEntityRepository caches
                // its underlying repository, so this also picks up a manager recreated
                // by resetManager() after a failed flush.
                $transfer = $this->managerRegistry->getRepository(Transfer::class)->findOneBy([
                    'reference' => $pendingTransfer->reference,
                ]);

                if ($transfer === null) {
                    throw new \RuntimeException('Pending transfer no longer exists.');
                }

                match ($pendingTransfer->type) {
                    TypeTransfer::DEPOSIT => $this->depositExecutor->execute($transfer->getToken()),
                    TypeTransfer::WITHDRAWAL => $this->withdrawalExecutor->execute($transfer->getToken()),
                    TypeTransfer::TRANSFER => $this->transferExecutor->execute($transfer->getToken()),
                };
            } catch (Throwable $exception) {
                $failed[] = $pendingTransfer;
                $this->logger->error('Automatic pending transfer execution failed.', [
                    'reference' => $pendingTransfer->reference,
                    'type' => $pendingTransfer->type->value,
                    'status' => $pendingTransfer->status->value,
                    'exception_class' => $exception::class,
                    'exception_message' => $this->diagnosticMessage($exception),
                    'entity_manager_open' => $this->isEntityManagerOpen(),
                ]);

                $this->resetClosedEntityManager();
            }
        }

        return $failed;
    }

    private function resetClosedEntityManager(): void
    {
        $manager = $this->managerRegistry->getManagerForClass(Transfer::class);
        if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
            // Doctrine explicitly requires a closed EntityManager to be discarded;
            // clearing it cannot make it usable again.
            $this->managerRegistry->resetManager();
        }
    }

    private function isEntityManagerOpen(): bool
    {
        $manager = $this->managerRegistry->getManagerForClass(Transfer::class);

        return !$manager instanceof EntityManagerInterface || $manager->isOpen();
    }

    private function diagnosticMessage(Throwable $exception): string
    {
        // Keep the message (especially BadRequestHttpException validation details),
        // but never log a trace, token, account number, or request payload.
        return mb_substr($exception->getMessage(), 0, 1000);
    }
}
