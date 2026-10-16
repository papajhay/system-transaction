<?php

declare(strict_types=1);

namespace App\Tests\Feature\Transactions;

use App\Entity\Account;
use App\Entity\Fee;
use App\Entity\Operation;
use App\Entity\Transfer;
use App\Enum\StatusAccount;
use App\Enum\StatusTransfer;
use App\Enum\TypeAccount;
use App\Enum\TypeFee;
use App\Enum\TypeTransfer;
use App\Service\TransferExecutor;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;

final class ExecuteTransferCommandTest extends TransactionTestCase
{
    #[Test]
    public function executesTransferByReferenceAndReportsFixedFee(): void
    {
        $receiver = $this->account('CMD-TRF-FIXED');
        $transfer = $this->pendingTransfer($receiver, '50.00', 'CMD-TRF-FIXED-REF');
        $this->entityManager->persist((new Fee())->setTransfer($transfer)->setType(TypeFee::FEE_CHARGED_FIXED)->setAmount(10.0));
        $this->entityManager->flush();

        $tester = $this->runCommand($transfer->getReference());

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('amount 50.00', $tester->getDisplay());
        self::assertStringContainsString('fee 10.00', $tester->getDisplay());
        $this->entityManager->clear();
        self::assertSame('90.00', $this->balance($this->account));
        self::assertSame('50.00', $this->balance($receiver));
        self::assertSame('10.15', $this->balance($this->systemAccount));
    }

    #[Test]
    public function appliesPercentageFeeAndPersistsRateAndAmount(): void
    {
        $receiver = $this->account('CMD-TRF-RATE');
        $transfer = $this->pendingTransfer($receiver, '100.00', 'CMD-TRF-RATE-REF');
        $this->entityManager->persist((new Fee())->setTransfer($transfer)->setType(TypeFee::FEE_CHARGED_RATE)->setRate(5.0));
        $this->entityManager->flush();

        self::assertSame(0, $this->runCommand($transfer->getToken())->getStatusCode());
        $this->entityManager->clear();
        $storedTransfer = $this->entityManager->getRepository(Transfer::class)->findOneBy(['reference' => $transfer->getReference()]);
        $fee = $this->entityManager->getRepository(Fee::class)->findOneBy(['transfer' => $storedTransfer]);

        self::assertSame(5.0, $fee?->getRate());
        self::assertSame('5.00', number_format($fee?->getAmount() ?? 0, 2, '.', ''));
        self::assertSame('45.00', $this->balance($this->account));
    }

    #[Test]
    public function freeFeeDoesNotChangeTransferAmount(): void
    {
        $receiver = $this->account('CMD-TRF-FREE');
        $transfer = $this->pendingTransfer($receiver, '25.00', 'CMD-TRF-FREE-REF');
        $this->entityManager->persist((new Fee())->setTransfer($transfer)->setType(TypeFee::FREE_CHARGED)->setAmount(0.0));
        $this->entityManager->flush();

        self::assertSame(0, $this->runCommand($transfer->getToken())->getStatusCode());
        $this->entityManager->clear();
        self::assertSame('125.00', $this->balance($this->account));
        self::assertSame('25.00', $this->balance($receiver));
    }

    #[Test]
    public function usesExplicitConfiguredDefaultWhenNoFeeConfigurationExists(): void
    {
        $receiver = $this->account('CMD-TRF-DEFAULT');
        $transfer = $this->pendingTransfer($receiver, '50.00', 'CMD-TRF-DEFAULT-REF');
        $this->entityManager->flush();

        $tester = $this->runCommand($transfer->getToken());

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('fee 5.00', $tester->getDisplay());
        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(Transfer::class)->findOneBy(['reference' => $transfer->getReference()]);
        self::assertSame(TypeFee::FEE_CHARGED_RATE, $stored?->getFeeType());
        self::assertSame(10.0, $stored?->getFeeRate());
        self::assertSame('5.00', number_format($stored?->getFeeAmount() ?? 0, 2, '.', ''));
    }

    #[Test]
    public function rejectsAmbiguousGlobalConfigurations(): void
    {
        $receiver = $this->account('CMD-TRF-AMBIGUOUS');
        $transfer = $this->pendingTransfer($receiver, '50.00', 'CMD-TRF-AMBIGUOUS-REF');
        $this->entityManager->persist((new Fee())->setType(TypeFee::FEE_CHARGED_FIXED)->setAmount(2.0));
        $this->entityManager->persist((new Fee())->setType(TypeFee::FEE_CHARGED_RATE)->setRate(4.0));
        $this->entityManager->flush();

        self::assertSame(1, $this->runCommand($transfer->getToken())->getStatusCode());
        self::assertSame('150.00', $this->balance($this->account));
    }

    #[Test]
    public function rejectsInvalidFixedAndPercentageConfigurations(): void
    {
        $receiver = $this->account('CMD-TRF-INVALID-FEE');
        $fixed = $this->pendingTransfer($receiver, '50.00', 'CMD-TRF-INVALID-FIXED');
        $this->entityManager->persist((new Fee())->setTransfer($fixed)->setType(TypeFee::FEE_CHARGED_FIXED)->setAmount(0.0));
        $rate = $this->pendingTransfer($receiver, '50.00', 'CMD-TRF-INVALID-RATE');
        $this->entityManager->persist((new Fee())->setTransfer($rate)->setType(TypeFee::FEE_CHARGED_RATE)->setRate(100.01));
        $this->entityManager->flush();

        self::assertSame(1, $this->runCommand($fixed->getToken())->getStatusCode());
        self::assertSame(1, $this->runCommand($rate->getToken())->getStatusCode());
        self::assertSame('150.00', $this->balance($this->account));
    }

    #[Test]
    public function rejectsUnknownWrongTypeInvalidAmountAndSameAccount(): void
    {
        $receiver = $this->account('CMD-TRF-INVALID');
        $wrongType = $this->pendingTransfer($receiver, '10.00', 'CMD-TRF-TYPE')->setType(TypeTransfer::DEPOSIT);
        $invalid = $this->pendingTransfer($receiver, '0.00', 'CMD-TRF-AMOUNT');
        $same = $this->pendingTransfer($this->account, '10.00', 'CMD-TRF-SAME');
        $this->entityManager->flush();

        self::assertSame(1, $this->runCommand('unknown-transfer')->getStatusCode());
        self::assertSame(1, $this->runCommand($wrongType->getToken())->getStatusCode());
        self::assertSame(1, $this->runCommand($invalid->getToken())->getStatusCode());
        self::assertSame(1, $this->runCommand($same->getToken())->getStatusCode());
    }

    #[Test]
    public function rejectsMissingAccountsAndInsufficientBalanceIncludingFees(): void
    {
        $receiver = $this->account('CMD-TRF-MISSING');
        $missing = $this->pendingTransfer($receiver, '10.00', 'CMD-TRF-MISSING-REF')->setSenderAccount(null);
        $insufficient = $this->pendingTransfer($receiver, '145.00', 'CMD-TRF-INSUFFICIENT');
        $this->entityManager->persist((new Fee())->setTransfer($insufficient)->setType(TypeFee::FEE_CHARGED_FIXED)->setAmount(10.0));
        $missingDestination = $this->pendingTransfer($receiver, '10.00', 'CMD-TRF-MISSING-DEST')->setReceiverAccount(null);
        $this->entityManager->flush();

        self::assertSame(1, $this->runCommand($missing->getToken())->getStatusCode());
        self::assertSame(1, $this->runCommand($missingDestination->getToken())->getStatusCode());
        self::assertSame(1, $this->runCommand($insufficient->getToken())->getStatusCode());
        self::assertSame('150.00', $this->balance($this->account));
    }

    #[Test]
    public function rollsBackWhenSystemFeeAccountIsUnavailable(): void
    {
        $receiver = $this->account('CMD-TRF-ROLLBACK');
        $transfer = $this->pendingTransfer($receiver, '50.00', 'CMD-TRF-ROLLBACK-REF');
        $this->entityManager->persist((new Fee())->setTransfer($transfer)->setType(TypeFee::FEE_CHARGED_FIXED)->setAmount(10.0));
        $this->systemAccount->setStatus(StatusAccount::SUSPENDED);
        $this->entityManager->flush();

        self::assertSame(1, $this->runCommand($transfer->getToken())->getStatusCode());
        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(Transfer::class)->findOneBy(['reference' => $transfer->getReference()]);
        self::assertSame(StatusTransfer::PENDING, $stored?->getStatus());
        self::assertSame('150.00', $this->balance($this->account));
        self::assertSame('0.00', $this->balance($receiver));
        self::assertSame([], $this->entityManager->getRepository(Operation::class)->findBy(['transfer' => $stored]));
    }

    #[Test]
    public function preventsDuplicateExecution(): void
    {
        $receiver = $this->account('CMD-TRF-DUPLICATE');
        $transfer = $this->pendingTransfer($receiver, '10.00', 'CMD-TRF-DUPLICATE-REF');
        $this->entityManager->flush();

        self::assertSame(0, $this->runCommand($transfer->getToken())->getStatusCode());
        self::assertSame(1, $this->runCommand($transfer->getToken())->getStatusCode());
        self::assertSame('139.00', $this->balance($this->account));
        self::assertSame('10.00', $this->balance($receiver));
    }

    private function runCommand(string $identifier): CommandTester
    {
        $command = new \App\Command\ExecuteTransferCommand(static::getContainer()->get(TransferExecutor::class));
        $tester = new CommandTester($command);
        $tester->execute(['token-or-reference' => $identifier]);
        return $tester;
    }

    private function pendingTransfer(Account $receiver, string $amount, string $reference): Transfer
    {
        $now = new DateTimeImmutable();
        $transfer = (new Transfer())
            ->setToken('token-' . strtolower($reference))
            ->setReference($reference)
            ->setSenderAccount($this->account)
            ->setReceiverAccount($receiver)
            ->setAmount($amount)
            ->setCurrency($this->currency)
            ->setReceivedAmount($amount)
            ->setReceivedCurrency($receiver->getCurrency())
            ->setExchangeRate('1.0000000000')
            ->setType(TypeTransfer::TRANSFER)
            ->setStatus(StatusTransfer::PENDING)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $this->entityManager->persist($transfer);
        return $transfer;
    }

    private function account(string $number): Account
    {
        $now = new DateTimeImmutable();
        $account = (new Account())
            ->setAccountNumber($number)->setBalance('0.00')
            ->setCurrency($this->currency)->setStatus(StatusAccount::ACTIVE)
            ->setType(TypeAccount::USER)->setCreatedAt($now)->setUpdatedAt($now);
        $this->entityManager->persist($account);
        $this->entityManager->flush();
        return $account;
    }

    private function balance(Account $account): string
    {
        $stored = $this->entityManager->find(Account::class, $account->getId());
        return number_format((float) ($stored?->getBalance() ?? 0), 2, '.', '');
    }
}
