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

#[AsMessageHandler]
final class ExecuteTransferMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DepositExecutor $depositExecutor,
        private readonly WithdrawalExecutor $withdrawalExecutor,
        private readonly TransferExecutor $transferExecutor,
    ) {
    }

    public function __invoke(ExecuteTransferMessage $message): void
    {
        $transfer = $this->entityManager->getRepository(Transfer::class)->findOneBy([
            'reference' => $message->reference,
        ]);

        if (!$transfer instanceof Transfer) {
            throw new BadRequestHttpException('Transfer was not found.');
        }

        // RabbitMQ redelivery and scheduler overlap are expected. The execution
        // services also lock and re-check the row inside their transaction.
        if ($transfer->getStatus() !== StatusTransfer::PENDING) {
            return;
        }

        match ($transfer->getType()) {
            TypeTransfer::DEPOSIT => $this->depositExecutor->execute($transfer->getToken()),
            TypeTransfer::WITHDRAWAL => $this->withdrawalExecutor->execute($transfer->getToken()),
            TypeTransfer::TRANSFER => $this->transferExecutor->execute($transfer->getToken()),
        };
    }
}
