<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
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

final class WithdrawalExecutor
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FeeCalculator $feeCalculator,
    )
    {
    }

    /** @return array{token: string, reference: string, status: string, amount: string, debited_amount: string, fee: string} */
    public function execute(string $tokenOrReference): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($tokenOrReference): array {
            $transfer = $this->entityManager->getRepository(Transfer::class)
                ->createQueryBuilder('transfer')
                ->andWhere('transfer.token = :value OR transfer.reference = :value')
                ->setParameter('value', $tokenOrReference)
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getOneOrNullResult();

            if (!$transfer instanceof Transfer) {
                throw new BadRequestHttpException('Withdrawal transfer was not found.');
            }
            if ($transfer->getType() !== TypeTransfer::WITHDRAWAL) {
                throw new BadRequestHttpException('The transfer is not a withdrawal.');
            }
            if ($transfer->getStatus() !== StatusTransfer::PENDING) {
                throw new BadRequestHttpException('Withdrawal has already been processed.');
            }
            if ($transfer->getExpiresAt() !== null && $transfer->getExpiresAt() < new DateTimeImmutable()) {
                throw new BadRequestHttpException('Withdrawal has expired.');
            }

            $amountCents = $this->cents($transfer->getAmount());
            if ($amountCents <= 0) {
                throw new BadRequestHttpException('The withdrawal amount must be greater than zero.');
            }

            $account = $transfer->getSenderAccount();
            if (!$account instanceof Account) {
                throw new BadRequestHttpException('The withdrawal source account does not exist.');
            }
            if ($account->getStatus() !== StatusAccount::ACTIVE) {
                throw new BadRequestHttpException('The withdrawal source account must be active.');
            }
            if ($account->getCurrency() === null) {
                throw new BadRequestHttpException('The withdrawal source account has no currency.');
            }
            if ($transfer->getCurrency()?->getId() !== $account->getCurrency()->getId()) {
                throw new BadRequestHttpException('The withdrawal source account does not match the transfer currency.');
            }

            $feeDetails = $this->feeCalculator->calculate(
                $amountCents,
                $transfer->getFee(),
                false,
                $transfer->getFeeType(),
            );
            $feeCents = $feeDetails['amount'];
            $debitCents = $amountCents + $feeCents;
            $this->entityManager->lock($account, LockMode::PESSIMISTIC_WRITE);
            $before = $this->cents($account->getBalance());
            if ($before < $debitCents) {
                throw new BadRequestHttpException('Insufficient balance for withdrawal and fees.');
            }

            $system = null;
            if ($feeCents > 0) {
                $system = $this->entityManager->getRepository(Account::class)->findOneBy([
                    'currency' => $account->getCurrency(),
                    'type' => TypeAccount::SYSTEM,
                ]);
                if (!$system instanceof Account || $system->getStatus() !== StatusAccount::ACTIVE) {
                    throw new BadRequestHttpException('The system account for withdrawal fees does not exist.');
                }
                $this->entityManager->lock($system, LockMode::PESSIMISTIC_WRITE);
            }

            $now = new DateTimeImmutable();
            $after = $before - $debitCents;
            $account->setBalance($this->money($after))->setUpdatedAt($now);
            $this->operation($account, TypeOperation::DEBIT, $debitCents, $before, $after, $transfer, $now);

            if ($system instanceof Account) {
                $systemBefore = $this->cents($system->getBalance());
                $systemAfter = $systemBefore + $feeCents;
                $system->setBalance($this->money($systemAfter))->setUpdatedAt($now);
                $this->operation($system, TypeOperation::CREDIT, $feeCents, $systemBefore, $systemAfter, $transfer, $now);
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
            $this->entityManager->flush();

            return [
                'token' => $transfer->getToken(),
                'reference' => $transfer->getReference(),
                'status' => $transfer->getStatus()->value,
                'amount' => $transfer->getAmount(),
                'debited_amount' => $this->money($debitCents),
                'fee' => $this->money($feeCents),
            ];
        });
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
