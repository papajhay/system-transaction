<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Transfer;
use App\Enum\StatusTransfer;
use App\Enum\TypeTransfer;
use App\Message\ExecuteWithdrawalMessage;
use App\Service\WithdrawalExecutor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler]
final class ExecuteWithdrawalMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WithdrawalExecutor $withdrawalExecutor,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExecuteWithdrawalMessage $message): void
    {
        $consumedAt = new \DateTimeImmutable();
        $this->logger->info('withdrawal.message_consumed', [
            'reference' => $message->reference,
            'consumed_at' => $consumedAt->format(\DateTimeImmutable::ATOM),
        ]);

        $withdrawal = $this->entityManager->getRepository(Transfer::class)->findOneBy([
            'reference' => $message->reference,
        ]);

        if (!$withdrawal instanceof Transfer || $withdrawal->getType() !== TypeTransfer::WITHDRAWAL) {
            throw new BadRequestHttpException('Withdrawal was not found.');
        }

        // A redelivered message must not execute an already completed withdrawal again.
        if ($withdrawal->getStatus() !== StatusTransfer::PENDING) {
            $this->logger->info('withdrawal.message_skipped', [
                'reference' => $message->reference,
                'status' => $withdrawal->getStatus()->value,
            ]);
            return;
        }

        $startedAt = new \DateTimeImmutable();
        $this->logger->info('withdrawal.execution_started', [
            'reference' => $message->reference,
            'started_at' => $startedAt->format(\DateTimeImmutable::ATOM),
        ]);

        try {
            $result = $this->withdrawalExecutor->execute($withdrawal->getToken());

            $this->logger->info('withdrawal.completed', [
                'reference' => $message->reference,
                'status' => $result['status'] ?? 'completed',
                'completed_at' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
            ]);
        } catch (Throwable $exception) {
            $this->logger->warning('withdrawal.processing_failed', [
                'reference' => $message->reference,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'retryable' => true,
            ]);
            throw $exception;
        }
    }
}
