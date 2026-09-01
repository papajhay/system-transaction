<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation as ApiOperation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\ExecuteWithdrawalInput;
use App\Dto\InitWithdrawalInput;
use App\Dto\TransactionResponse;
use App\Entity\Account;
use App\Entity\Fee;
use App\Entity\Operation;
use App\Entity\Transfer;
use App\Enum\StatusTransfer;
use App\Enum\TypeFee;
use App\Enum\TypeOperation;
use App\Enum\TypeTransfer;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Uuid;

final class WithdrawalProcessor implements ProcessorInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
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
        return $this->entityManager->wrapInTransaction(function () use ($input): TransactionResponse {
            $transfer = $this->entityManager->getRepository(Transfer::class)
                ->createQueryBuilder('transfer')
                ->andWhere('transfer.token = :token')
                ->setParameter('token', $input->token)
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getSingleResult();

            if ($transfer->getStatus() !== StatusTransfer::PENDING
                || ($transfer->getExpiresAt() !== null && $transfer->getExpiresAt() < new DateTimeImmutable())) {
                throw new BadRequestHttpException('Transfer has already been processed or expired.');
            }

            $account = $transfer->getSenderAccount();
            if ($account === null) {
                throw new BadRequestHttpException('The sender account does not exist.');
            }

            $before = $account->getBalance();
            $beforeCents = (int) round((float) $before * 100);
            $amountCents = (int) round((float) $transfer->getAmount() * 100);
            $after = number_format(($beforeCents - $amountCents) / 100, 2, '.', '');
            $now = new DateTimeImmutable();

            $account->setBalance($after)->setUpdatedAt($now);

            $operation = (new Operation())
                ->setAccount($account)
                ->setTransfer($transfer)
                ->setType(TypeOperation::DEBIT)
                ->setAmount($transfer->getAmount())
                ->setBalanceBefore($before)
                ->setBalanceAfter($after)
                ->setCreatedAt($now)
                ->setUpdatedAt($now);

            $fee = (new Fee())
                ->setTransfer($transfer)
                ->setType(TypeFee::FREE_CHARGED)
                ->setAmount('0.00')
                ->setCreatedAt($now)
                ->setUpdatedAt($now);

            $this->entityManager->persist($operation);
            $this->entityManager->persist($fee);
            $transfer->markCompleted();
            $this->entityManager->flush();

            return new TransactionResponse(201, 'Withdrawal executed successfully', [
                'token' => $transfer->getToken(),
                'reference' => $transfer->getReference(),
                'status' => $transfer->getStatus()->value,
                'amount' => $transfer->getAmount(),
                'type' => $transfer->getType()->value,
                'description' => $transfer->getDescription(),
            ]);
        });
    }
}
