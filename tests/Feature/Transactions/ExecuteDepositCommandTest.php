<?php

declare(strict_types=1);

namespace App\Tests\Feature\Transactions;

use App\Entity\Fee;
use App\Entity\Operation;
use App\Entity\Transfer;
use App\Enum\StatusTransfer;
use App\Enum\TypeFee;
use App\Enum\TypeTransfer;
use App\Service\DepositExecutor;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;

final class ExecuteDepositCommandTest extends TransactionTestCase
{
    #[Test]
    public function executesByReferenceAndAppliesFixedFee(): void
    {
        $this->entityManager->persist((new Fee())->setType(TypeFee::FEE_CHARGED_FIXED)->setAmount(10.0));
        $transfer = $this->pendingDeposit('CMD-FIXED', '100.00');
        $this->entityManager->flush();

        $tester = $this->runCommand($transfer->getReference());

        self::assertSame(0, $tester->getStatusCode());
        $this->entityManager->clear();
        $account = $this->entityManager->find(\App\Entity\Account::class, $this->account->getId());
        $system = $this->entityManager->find(\App\Entity\Account::class, $this->systemAccount->getId());
        $stored = $this->entityManager->getRepository(Fee::class)->findOneBy([
            'transfer' => $this->entityManager->getRepository(Transfer::class)->findOneBy(['reference' => 'CMD-FIXED']),
        ]);

        self::assertSame('240.00', number_format((float) ($account?->getBalance() ?? 0), 2, '.', ''));
        self::assertSame('10.15', number_format((float) ($system?->getBalance() ?? 0), 2, '.', ''));
        self::assertSame(TypeFee::FEE_CHARGED_FIXED, $stored?->getType());
        self::assertSame('10.00', number_format($stored?->getAmount() ?? 0, 2, '.', ''));
    }

    #[Test]
    public function storesPercentageRateAndCalculatedFeeAmount(): void
    {
        $this->entityManager->persist((new Fee())->setType(TypeFee::FEE_CHARGED_RATE)->setRate(2.5));
        $transfer = $this->pendingDeposit('CMD-RATE', '100.00');
        $this->entityManager->flush();

        self::assertSame(0, $this->runCommand($transfer->getToken())->getStatusCode());
        $this->entityManager->clear();
        $storedTransfer = $this->entityManager->getRepository(Transfer::class)->findOneBy(['token' => $transfer->getToken()]);
        $stored = $this->entityManager->getRepository(Fee::class)->findOneBy(['transfer' => $storedTransfer]);

        self::assertSame(2.5, $stored?->getRate());
        self::assertSame('2.50', number_format($stored?->getAmount() ?? 0, 2, '.', ''));
        self::assertSame('247.50', number_format((float) ($this->entityManager->find(\App\Entity\Account::class, $this->account->getId())?->getBalance() ?? 0), 2, '.', ''));
    }

    #[Test]
    public function recordsZeroFeeAndCreditsGrossAmountWhenFree(): void
    {
        $this->entityManager->persist((new Fee())->setType(TypeFee::FREE_CHARGED)->setAmount(0.0));
        $transfer = $this->pendingDeposit('CMD-FREE', '25.00');
        $this->entityManager->flush();

        self::assertSame(0, $this->runCommand($transfer->getToken())->getStatusCode());
        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(Fee::class)->findOneBy([
            'transfer' => $this->entityManager->getRepository(Transfer::class)->findOneBy(['token' => $transfer->getToken()]),
        ]);

        self::assertSame(TypeFee::FREE_CHARGED, $stored?->getType());
        self::assertSame('175.00', number_format((float) ($this->entityManager->find(\App\Entity\Account::class, $this->account->getId())?->getBalance() ?? 0), 2, '.', ''));
    }

    #[Test]
    public function rejectsInvalidAmountAndMissingAccount(): void
    {
        $invalid = $this->pendingDeposit('CMD-INVALID', '0.00');
        $missing = $this->pendingDeposit('CMD-MISSING', '10.00');
        $missing->setReceiverAccount(null);
        $this->entityManager->flush();

        self::assertSame(1, $this->runCommand($invalid->getToken())->getStatusCode());
        self::assertSame(1, $this->runCommand($missing->getToken())->getStatusCode());
    }

    #[Test]
    public function rejectsDuplicateExecution(): void
    {
        $transfer = $this->pendingDeposit('CMD-DUPLICATE', '10.00');
        $this->entityManager->flush();

        self::assertSame(0, $this->runCommand($transfer->getToken())->getStatusCode());
        self::assertSame(1, $this->runCommand($transfer->getToken())->getStatusCode());
    }

    #[Test]
    public function rejectsANonDepositTransfer(): void
    {
        $transfer = $this->pendingDeposit('CMD-NON-DEPOSIT', '10.00')->setType(TypeTransfer::TRANSFER);
        $this->entityManager->flush();

        self::assertSame(1, $this->runCommand($transfer->getToken())->getStatusCode());
    }

    #[Test]
    public function rollsBackBalanceAndLedgerWhenFeeSettlementFails(): void
    {
        $this->entityManager->persist((new Fee())->setType(TypeFee::FEE_CHARGED_FIXED)->setAmount(10.0));
        $transfer = $this->pendingDeposit('CMD-ROLLBACK', '100.00');
        $this->entityManager->remove($this->systemAccount);
        $this->entityManager->flush();

        self::assertSame(1, $this->runCommand($transfer->getToken())->getStatusCode());
        $this->entityManager->clear();
        $storedTransfer = $this->entityManager->getRepository(Transfer::class)->findOneBy(['token' => $transfer->getToken()]);

        self::assertSame(StatusTransfer::PENDING, $storedTransfer?->getStatus());
        self::assertSame('150.00', number_format((float) ($this->entityManager->find(\App\Entity\Account::class, $this->account->getId())?->getBalance() ?? 0), 2, '.', ''));
        self::assertSame([], $this->entityManager->getRepository(Operation::class)->findBy(['transfer' => $storedTransfer]));
    }

    private function runCommand(string $identifier): CommandTester
    {
        $command = new \App\Command\ExecuteDepositCommand(static::getContainer()->get(DepositExecutor::class));
        $tester = new CommandTester($command);
        $tester->execute(['token-or-reference' => $identifier]);
        return $tester;
    }

    private function pendingDeposit(string $reference, string $amount): Transfer
    {
        $now = new DateTimeImmutable();
        $transfer = (new Transfer())
            ->setToken('token-' . strtolower($reference))
            ->setReference($reference)
            ->setReceiverAccount($this->account)
            ->setAmount($amount)
            ->setCurrency($this->currency)
            ->setReceivedAmount($amount)
            ->setReceivedCurrency($this->currency)
            ->setExchangeRate('1.0000000000')
            ->setType(TypeTransfer::DEPOSIT)
            ->setStatus(StatusTransfer::PENDING)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $this->entityManager->persist($transfer);
        return $transfer;
    }
}
