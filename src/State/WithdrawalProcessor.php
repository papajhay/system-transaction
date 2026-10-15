<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation as ApiOperation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\ExecuteWithdrawalInput;
use App\Dto\InitWithdrawalInput;
use App\Dto\TransactionResponse;
use App\Entity\Account;
use App\Entity\Transfer;
use App\Enum\StatusTransfer;
use App\Enum\TypeTransfer;
use App\Service\WithdrawalExecutor;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Uuid;

final class WithdrawalProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WithdrawalExecutor $withdrawalExecutor,
    )
    {
    }

    public function process(
        mixed $data,
        ApiOperation $operation,
        array $uriVariables = [],
        array $context = [],
    ): TransactionResponse {
        return $data instanceof InitWithdrawalInput
            ? $this->initialize($data)
            : $this->execute($data);
    }

    private function initialize(InitWithdrawalInput $input): TransactionResponse
    {
        $account = $this->entityManager->getRepository(Account::class)->findOneBy([
            'accountNumber' => $input->account_number,
        ]);

        if ($account === null) {
            throw new \InvalidArgumentException('The account does not exist.');
        }

        if ((float) $account->getBalance() < (float) $input->amount) {
            throw new BadRequestHttpException('Insufficient balance');
        }

        $now = new DateTimeImmutable();
        $transfer = (new Transfer())
            ->setToken(Uuid::v4()->toRfc4122())
            ->setReference('WIT-' . strtoupper(bin2hex(random_bytes(5))))
            ->setSenderAccount($account)
            ->setAmount(number_format((float) $input->amount, 6, '.', ''))
            ->setCurrency($account->getCurrency())
            ->setReceivedAmount(number_format((float) $input->amount, 2, '.', ''))
            ->setReceivedCurrency($account->getCurrency())
            ->setExchangeRate('1.0000000000')
            ->setType(TypeTransfer::WITHDRAWAL)
            ->setStatus(StatusTransfer::PENDING)
            ->setDescription($input->description)
            ->setExpiresAt($now->modify('+10 minutes'))
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        $this->entityManager->persist($transfer);
        $this->entityManager->flush();

        return new TransactionResponse(201, 'Withdrawal initialized successfully', [
            'token' => $transfer->getToken(),
            'reference' => $transfer->getReference(),
        ]);
    }

    private function execute(ExecuteWithdrawalInput $input): TransactionResponse
    {
        $result = $this->withdrawalExecutor->execute((string) $input->token);
        $transfer = $this->entityManager->getRepository(Transfer::class)->findOneBy(['token' => $result['token']]);

        return new TransactionResponse(201, 'Withdrawal executed successfully', [
            'token' => $result['token'],
            'reference' => $result['reference'],
            'status' => $result['status'],
            'amount' => $result['amount'],
            'type' => $transfer?->getType()->value,
            'description' => $transfer?->getDescription(),
        ]);
    }
}
