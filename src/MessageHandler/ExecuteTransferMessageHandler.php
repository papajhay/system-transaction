<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Transfer;
use App\Enum\StatusTransfer;
use App\Enum\TypeTransfer;
use App\Message\ExecuteTransferMessage;
use App\Service\DepositExecutor;
use App\Service\TransferExecutor;
use App\Service\WithdrawalExecutor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Psr\Log\LoggerInterface;
use Throwable;

#[AsMessageHandler]
final class ExecuteTransferMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DepositExecutor $depositExecutor,
        private readonly WithdrawalExecutor $withdrawalExecutor,
        private readonly TransferExecutor $transferExecutor,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExecuteTransferMessage $message): void
    {
        $this->logger->info('transfer.message_consumed', [
            'reference' => $message->reference,
            'consumed_at' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
        ]);

        $transfer = $this->entityManager->getRepository(Transfer::class)->findOneBy([
            'reference' => $message->reference,
        ]);

        if (!$transfer instanceof Transfer) {
            throw new BadRequestHttpException('Transfer was not found.');
        }

        // RabbitMQ redelivery and scheduler overlap are expected. The execution
        // services also lock and re-check the row inside their transaction.
        if ($transfer->getStatus() !== StatusTransfer::PENDING) {
            $this->logger->info('transfer.message_skipped', [
                'reference' => $message->reference,
                'status' => $transfer->getStatus()->value,
            ]);
            return;
        }

        $this->logger->info('transfer.completion_started', [
            'reference' => $transfer->getReference(),
            'type' => $transfer->getType()->value,
            'started_at' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
        ]);

        try {
            $result = match ($transfer->getType()) {
                TypeTransfer::DEPOSIT => $this->depositExecutor->execute($transfer->getToken()),
                TypeTransfer::WITHDRAWAL => $this->withdrawalExecutor->execute($transfer->getToken()),
                TypeTransfer::TRANSFER => $this->transferExecutor->execute($transfer->getToken()),
            };

            $this->logger->info('transfer.completed', [
                'reference' => $transfer->getReference(),
                'status' => $result['status'] ?? 'completed',
                'completed_at' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
            ]);
        } catch (Throwable $exception) {
            $this->logger->warning('transfer.processing_failed', [
                'reference' => $message->reference,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }
}
