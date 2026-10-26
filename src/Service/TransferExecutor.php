<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Entity\Conversion;
use App\Entity\Fee;
use App\Entity\Operation as LedgerOperation;
use App\Entity\Transfer;
use App\Enum\StatusAccount;
use App\Enum\StatusTransfer;
use App\Enum\TypeAccount;
use App\Enum\TypeFee;
use App\Enum\TypeOperation;
use App\Enum\TypeTransfer;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class TransferExecutor implements PendingTransferExecutionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FeeCalculator $feeCalculator,
    ) {
    }

    /** @return array{token: string, reference: string, status: string, amount: string, fee: string, debited_amount: string} */
    public function execute(string $tokenOrReference, bool $allowSameAccount = false): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($tokenOrReference, $allowSameAccount): array {
            $transfer = $this->find($tokenOrReference, true);
            $this->apply($transfer, $allowSameAccount);
            $this->entityManager->flush();

            return $this->output($transfer);
        });
    }

    /** Apply one pending transfer inside an existing transaction. */
    public function apply(Transfer $transfer, bool $allowSameAccount = false): void
    {
        if ($transfer->getType() !== TypeTransfer::TRANSFER) {
            throw new BadRequestHttpException('The transfer is not an account transfer.');
        }
        if ($transfer->getStatus() !== StatusTransfer::PENDING) {
            throw new BadRequestHttpException('Transfer has already been processed.');
        }
        if ($transfer->getExpiresAt() !== null && $transfer->getExpiresAt() < new DateTimeImmutable()) {
            throw new BadRequestHttpException('Transfer has expired.');
        }

        $sender = $transfer->getSenderAccount();
        $receiver = $transfer->getReceiverAccount();
        if (!$sender instanceof Account || !$receiver instanceof Account) {
            throw new BadRequestHttpException('The transfer source and destination accounts must exist.');
        }
        if (!$allowSameAccount && $sender->getId() !== null && $sender->getId() === $receiver->getId()) {
            throw new BadRequestHttpException('The source and destination accounts must be different.');
        }
        $this->validateAccount($sender, 'source');
        $this->validateAccount($receiver, 'destination');

        $senderCurrency = $sender->getCurrency();
        $receiverCurrency = $receiver->getCurrency();
        if ($transfer->getCurrency()?->getId() !== $senderCurrency?->getId()) {
            throw new BadRequestHttpException('The transfer source account does not match the transfer currency.');
        }
        if ($transfer->getReceivedCurrency()?->getId() !== $receiverCurrency?->getId()) {
            throw new BadRequestHttpException('The transfer destination account does not match the received currency.');
        }

        $amountCents = $this->cents($transfer->getAmount());
        if ($amountCents <= 0) {
            throw new BadRequestHttpException('The transfer amount must be greater than zero.');
        }

        $crossCurrency = $senderCurrency->getId() !== $receiverCurrency->getId();
        $feeDetails = $crossCurrency
            ? ['amount' => 0, 'type' => TypeFee::FREE_CHARGED, 'rate' => null]
            : $this->feeCalculator->calculateForTransfer($amountCents, $transfer, true);
        $feeCents = $feeDetails['amount'];
        $debitCents = $amountCents + $feeCents;

        $this->entityManager->lock($sender, LockMode::PESSIMISTIC_WRITE);
        $this->entityManager->lock($receiver, LockMode::PESSIMISTIC_WRITE);
        $senderBefore = $this->cents($sender->getBalance());
        if ($senderBefore < $debitCents) {
            throw new BadRequestHttpException('Insufficient balance for transfer and fees.');
        }

        $now = new DateTimeImmutable();
        if (!$crossCurrency) {
            $system = $this->systemAccount($sender);
            $this->entityManager->lock($system, LockMode::PESSIMISTIC_WRITE);
            $this->debit($sender, $debitCents, $transfer, $now);
            $this->credit($receiver, $amountCents, $transfer, $now);
            if ($feeCents > 0) {
                $this->credit($system, $feeCents, $transfer, $now);
            }
        } else {
            $sourceSystem = $this->systemAccount($sender);
            $targetSystem = $this->systemAccount($receiver);
            $this->entityManager->lock($sourceSystem, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->lock($targetSystem, LockMode::PESSIMISTIC_WRITE);
            $convertedCents = $this->cents($transfer->getReceivedAmount());
            if ($convertedCents <= 0) {
                throw new BadRequestHttpException('The received transfer amount must be greater than zero.');
            }
            $this->debit($sender, $amountCents, $transfer, $now);
            $this->credit($sourceSystem, $amountCents, $transfer, $now);
            $this->debit($sourceSystem, $amountCents, $transfer, $now);
            // The target system receives the source amount; the converted
            // amount is debited when it is paid out to the receiver.
            $this->credit($targetSystem, $amountCents, $transfer, $now);
            $this->debit($targetSystem, $convertedCents, $transfer, $now);
            $this->credit($receiver, $convertedCents, $transfer, $now);
            $this->entityManager->persist((new Conversion())
                ->setTransfer($transfer)
                ->setFromCurrency($senderCurrency)
                ->setToCurrency($receiverCurrency)
                ->setExchangeRate($transfer->getExchangeRate())
                ->setSourceAmount($this->money($amountCents))
                ->setTargetAmount($this->money($convertedCents))
                ->setCreatedAt($now)->setUpdatedAt($now));
        }

        $fee = $transfer->getFee() ?? new Fee();
        $fee->setTransfer($transfer)
            ->setType($feeDetails['type'])
            ->setRate($feeDetails['rate'])
            ->setAmount($feeCents / 100)
            ->setCreatedAt($fee->getCreatedAt() ?? $now)
            ->setUpdatedAt($now);
        $this->entityManager->persist($fee);
        $transfer->setFeeType($feeDetails['type'])
            ->setFeeRate($feeDetails['rate'] ?? 0.0)
            ->setFeeAmount($feeCents / 100)
            ->markCompleted();
    }

    public function find(string $tokenOrReference, bool $lock = false): Transfer
    {
        $query = $this->entityManager->getRepository(Transfer::class)
            ->createQueryBuilder('transfer')
            ->andWhere('transfer.token = :value OR transfer.reference = :value')
            ->setParameter('value', $tokenOrReference)
            ->getQuery();
        if ($lock) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }
        $transfer = $query->getOneOrNullResult();
        if (!$transfer instanceof Transfer) {
            throw new BadRequestHttpException('Transfer was not found.');
        }
        return $transfer;
    }

    /** @return array{token: string, reference: string, status: string, amount: string, fee: string, debited_amount: string} */
    private function output(Transfer $transfer): array
    {
        return [
            'token' => $transfer->getToken(),
            'reference' => $transfer->getReference(),
            'status' => $transfer->getStatus()->value,
            'amount' => $transfer->getAmount(),
            'fee' => $transfer->getFeeAmount() !== 0.0 ? $this->money($this->cents((string) $transfer->getFeeAmount())) : '0.00',
            'debited_amount' => $this->money($this->cents($transfer->getAmount()) + $this->cents((string) $transfer->getFeeAmount())),
        ];
    }

    private function validateAccount(Account $account, string $role): void
    {
        if ($account->getStatus() !== StatusAccount::ACTIVE) {
            throw new BadRequestHttpException(sprintf('The transfer %s account must be active.', $role));
        }
        if ($account->getCurrency() === null) {
            throw new BadRequestHttpException(sprintf('The transfer %s account has no currency.', $role));
        }
    }

    private function systemAccount(Account $account): Account
    {
        $system = $this->entityManager->getRepository(Account::class)->findOneBy([
            'currency' => $account->getCurrency(),
            'type' => TypeAccount::SYSTEM,
        ]);
        if (!$system instanceof Account || $system->getStatus() !== StatusAccount::ACTIVE) {
            throw new BadRequestHttpException('The system account does not exist or is not active.');
        }
        return $system;
    }

    private function debit(Account $account, int $amountCents, Transfer $transfer, DateTimeImmutable $now): void
    {
        $before = $this->cents($account->getBalance());
        $after = $before - $amountCents;
        $account->setBalance($this->money($after))->setUpdatedAt($now);
        $this->operation($account, TypeOperation::DEBIT, $amountCents, $before, $after, $transfer, $now);
    }

    private function credit(Account $account, int $amountCents, Transfer $transfer, DateTimeImmutable $now): void
    {
        $before = $this->cents($account->getBalance());
        $after = $before + $amountCents;
        $account->setBalance($this->money($after))->setUpdatedAt($now);
        $this->operation($account, TypeOperation::CREDIT, $amountCents, $before, $after, $transfer, $now);
    }

    private function operation(Account $account, TypeOperation $type, int $amount, int $before, int $after, Transfer $transfer, DateTimeImmutable $now): void
    {
        $this->entityManager->persist((new LedgerOperation())
            ->setAccount($account)->setTransfer($transfer)->setType($type)
            ->setAmount($this->money($amount))->setBalanceBefore($this->money($before))
            ->setBalanceAfter($this->money($after))->setCreatedAt($now)->setUpdatedAt($now));
    }

    private function cents(string $amount): int
    {
        if (!preg_match('/^\s*(\d+)(?:\.(\d{1,6}))?\s*$/', $amount, $matches)) {
            return 0;
        }
        $fraction = str_pad($matches[2] ?? '', 3, '0');
        $cents = (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            ++$cents;
        }
        return ((int) $matches[1] * 100) + $cents;
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
