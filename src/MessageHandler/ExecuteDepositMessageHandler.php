<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Transfer;
use App\Enum\StatusTransfer;
use App\Enum\TypeTransfer;
use App\Message\ExecuteDepositMessage;
use App\Service\DepositExecutor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler]
final class ExecuteDepositMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DepositExecutor $depositExecutor,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExecuteDepositMessage $message): void
    {
        $this->logger->info('deposit.message_consumed', [
            'reference' => $message->reference,
            'consumed_at' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
        ]);

        $deposit = $this->entityManager->getRepository(Transfer::class)->findOneBy([
            'reference' => $message->reference,
        ]);

        if (!$deposit instanceof Transfer || $deposit->getType() !== TypeTransfer::DEPOSIT) {
            throw new BadRequestHttpException('Deposit was not found.');
        }

        // Redeliveries after a successful execution must not credit the account again.
        if ($deposit->getStatus() !== StatusTransfer::PENDING) {
            $this->logger->info('deposit.message_skipped', [
                'reference' => $message->reference,
                'status' => $deposit->getStatus()->value,
            ]);
            return;
        }

        $this->logger->info('deposit.execution_started', [
            'reference' => $message->reference,
            'started_at' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
            'status' => $deposit->getStatus()->value,
        ]);

        try {
            $result = $this->depositExecutor->execute($deposit->getToken());

            $this->logger->info('deposit.completed', [
                'reference' => $message->reference,
                'status' => $result['status'] ?? 'completed',
                'completed_at' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
            ]);
        } catch (Throwable $exception) {
            $this->logger->warning('deposit.processing_failed', [
                'reference' => $message->reference,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'retryable' => true,
            ]);
            throw $exception;
        }
    }
}
