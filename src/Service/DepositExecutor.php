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

final class DepositExecutor implements PendingTransferExecutionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FeeCalculator $feeCalculator,
    )
    {
    }

    /** @return array{token: string, reference: string, status: string, amount: string, credited_amount: string, fee: string} */
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
                throw new BadRequestHttpException('Deposit transfer was not found.');
            }
            if ($transfer->getType() !== TypeTransfer::DEPOSIT) {
                throw new BadRequestHttpException('The transfer is not a deposit.');
            }
            if ($transfer->getStatus() !== StatusTransfer::PENDING) {
                throw new BadRequestHttpException('Deposit has already been processed.');
            }
            if ($transfer->getExpiresAt() !== null && $transfer->getExpiresAt() < new DateTimeImmutable()) {
                throw new BadRequestHttpException('Deposit has expired.');
            }

            $amountCents = $this->cents($transfer->getAmount());
            if ($amountCents <= 0) {
                throw new BadRequestHttpException('The deposit amount must be greater than zero.');
            }

            $account = $transfer->getReceiverAccount();
            if (!$account instanceof Account) {
                throw new BadRequestHttpException('The deposit account does not exist.');
            }
            if ($account->getStatus() !== StatusAccount::ACTIVE || $account->getCurrency() === null) {
                throw new BadRequestHttpException('The deposit account is not valid.');
            }
            $this->entityManager->lock($account, LockMode::PESSIMISTIC_WRITE);

            $feeDetails = $this->feeCalculator->calculateForTransfer($amountCents, $transfer);
            $feeCents = $feeDetails['amount'];
            $this->recordFee($transfer, $feeDetails, new DateTimeImmutable());
            $creditedCents = $amountCents - $feeCents;
            if ($creditedCents < 0) {
                throw new BadRequestHttpException('The deposit fee exceeds the deposit amount.');
            }

            $now = new DateTimeImmutable();
            $before = $this->cents($account->getBalance());
            $after = $before + $creditedCents;
            $account->setBalance($this->money($after))->setUpdatedAt($now);
            $this->operation($account, TypeOperation::CREDIT, $creditedCents, $before, $after, $transfer, $now);

            if ($feeCents > 0) {
                $system = $this->entityManager->getRepository(Account::class)->findOneBy([
                    'currency' => $account->getCurrency(),
                    'type' => TypeAccount::SYSTEM,
                ]);
                if (!$system instanceof Account || $system->getStatus() !== StatusAccount::ACTIVE) {
                    throw new BadRequestHttpException('The system account for deposit fees does not exist.');
                }
                $this->entityManager->lock($system, LockMode::PESSIMISTIC_WRITE);
                $systemBefore = $this->cents($system->getBalance());
                $systemAfter = $systemBefore + $feeCents;
                $system->setBalance($this->money($systemAfter))->setUpdatedAt($now);
                $this->operation($system, TypeOperation::CREDIT, $feeCents, $systemBefore, $systemAfter, $transfer, $now);
            }

            $transfer->markCompleted();
            $this->entityManager->flush();

            return [
                'token' => $transfer->getToken(),
                'reference' => $transfer->getReference(),
                'status' => $transfer->getStatus()->value,
                'amount' => $transfer->getAmount(),
                'credited_amount' => $this->money($creditedCents),
                'fee' => $this->money($feeCents),
            ];
        });
    }

    /** @param array{amount:int,type:TypeFee,rate:?float} $feeDetails */
    private function recordFee(Transfer $transfer, array $feeDetails, DateTimeImmutable $now): void
    {
        $transfer->setFeeType($feeDetails['type'])
            ->setFeeRate($feeDetails['rate'] ?? 0.0)
            ->setFeeAmount($feeDetails['amount'] / 100);
        $fee = $transfer->getFee() ?? (new Fee())->setTransfer($transfer)->setCreatedAt($now);
        $fee->setType($feeDetails['type'])
            ->setRate($feeDetails['rate'])
            ->setAmount($feeDetails['amount'] / 100)
            ->setUpdatedAt($now);
        $this->entityManager->persist($fee);
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
