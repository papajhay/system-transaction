<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation as ApiOperation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\ExecuteDepositInput;
use App\Dto\InitDepositInput;
use App\Dto\TransactionResponse;
use App\Entity\Account;
use App\Entity\Transfer;
use App\Enum\StatusTransfer;
use App\Enum\TypeTransfer;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\DepositExecutor;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;
use App\Message\ExecuteTransferMessage;

final class DepositProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DepositExecutor $depositExecutor,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function process(
        mixed $data,
        ApiOperation $operation,
        array $uriVariables = [],
        array $context = [],
    ): TransactionResponse {
        return $data instanceof InitDepositInput
            ? $this->initialize($data)
            : $this->execute($data);
    }

    private function initialize(
        InitDepositInput $input,
    ): TransactionResponse {
        $account = $this->entityManager
            ->getRepository(Account::class)
            ->findOneBy([
                'accountNumber' => $input->account_number,
            ]);

        if ($account === null) {
            throw new \InvalidArgumentException(
                'The account does not exist.'
            );
        }

        $now = new DateTimeImmutable();

        $transfer = (new Transfer())
            ->setToken(Uuid::v4()->toRfc4122())
            ->setReference(
                'DEP-' . strtoupper(bin2hex(random_bytes(5)))
            )
            ->setReceiverAccount($account)
            ->setAmount(
                number_format(
                    (float) $input->amount,
                    6,
                    '.',
                    ''
                )
            )
            ->setCurrency($account->getCurrency())
            ->setReceivedAmount(number_format((float) $input->amount, 2, '.', ''))
            ->setReceivedCurrency($account->getCurrency())
            ->setExchangeRate('1.0000000000')
            ->setType(TypeTransfer::DEPOSIT)
            ->setStatus(StatusTransfer::PENDING)
            ->setDescription($input->description)
            ->setExpiresAt($now->modify('+10 minutes'))
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        $this->entityManager->persist($transfer);
        $this->entityManager->flush();
        $this->messageBus->dispatch(new ExecuteTransferMessage($transfer->getReference()));

        return new TransactionResponse(
            201,
            'Deposit initialized successfully',
            [
                'token' => $transfer->getToken(),
                'reference' => $transfer->getReference(),
            ],
        );
    }

    private function execute(
        ExecuteDepositInput $input,
    ): TransactionResponse {
        $result = $this->depositExecutor->execute((string) $input->token);
        $transfer = $this->entityManager->getRepository(Transfer::class)->findOneBy(['token' => $result['token']]);

        return new TransactionResponse(201, 'Deposit executed successfully', [
            'token' => $result['token'],
            'reference' => $result['reference'],
            'status' => $result['status'],
            'amount' => $result['amount'],
            'type' => $transfer?->getType()->value,
            'description' => $transfer?->getDescription(),
        ]);
    }
}
