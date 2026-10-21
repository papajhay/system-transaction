<?php

declare(strict_types=1);

namespace App\Tests\Feature\Transactions;

use App\Entity\Account;
use App\Entity\Fee;
use App\Entity\Operation;
use App\Entity\Transfer;
use App\Enum\StatusAccount;
use App\Enum\StatusTransfer;
use App\Enum\TypeFee;
use App\Enum\TypeTransfer;
use App\Service\WithdrawalExecutor;
use App\Service\TransferExecutionDelay;
use App\Tests\Double\RecordingTransferExecutionDelay;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;

final class ExecuteWithdrawalCommandTest extends TransactionTestCase
{
    #[Test]
    public function validatesSelectionAndDelayOptions(): void
    {
        self::assertSame(2, $this->runCommand(null, ['--reference' => 'REF', '--token' => 'TOKEN'])->getStatusCode());
        self::assertSame(2, $this->runCommand(null, ['--reference' => 'REF', '--time' => '1.5'])->getStatusCode());
    }

    #[Test]
    public function waitsBeforeExecutingWhenTimeIsProvided(): void
    {
        $transfer = $this->pendingWithdrawal('CMD-WIT-DELAY', '10.00')
            ->setFeeType(TypeFee::FEE_CHARGED_FIXED)
            ->setFeeAmount(27.0);
        $this->entityManager->flush();
        $delay = new RecordingTransferExecutionDelay();

        $tester = $this->runCommand(null, ['--token' => $transfer->getToken(), '--time' => '7'], $delay);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('fee 27.00', $tester->getDisplay());
        self::assertSame([7], $delay->delays);
    }

    #[Test]
    public function executesByReferenceAndDebitsAmountPlusFixedFee(): void
    {
        $transfer = $this->pendingWithdrawal('CMD-WIT-FIXED', '100.00');
        $this->entityManager->persist((new Fee())->setTransfer($transfer)->setType(TypeFee::FEE_CHARGED_FIXED)->setAmount(10.0));
        $this->entityManager->flush();

        $tester = $this->runCommand($transfer->getReference());

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('fee 10.00', $tester->getDisplay());
        $this->entityManager->clear();
        $account = $this->entityManager->find(Account::class, $this->account->getId());
        $system = $this->entityManager->find(Account::class, $this->systemAccount->getId());
        $storedTransfer = $this->entityManager->getRepository(Transfer::class)->findOneBy(['reference' => $transfer->getReference()]);
        $fee = $this->entityManager->getRepository(Fee::class)->findOneBy(['transfer' => $storedTransfer]);

        self::assertSame('40.00', number_format((float) ($account?->getBalance() ?? 0), 2, '.', ''));
        self::assertSame('10.15', number_format((float) ($system?->getBalance() ?? 0), 2, '.', ''));
        self::assertSame(TypeFee::FEE_CHARGED_FIXED, $fee?->getType());
        self::assertSame('10.00', number_format($fee?->getAmount() ?? 0, 2, '.', ''));
    }

    #[Test]
    public function storesPercentageRateAndCalculatedFeeAmount(): void
    {
        $transfer = $this->pendingWithdrawal('CMD-WIT-RATE', '100.00');
        $this->entityManager->persist((new Fee())->setTransfer($transfer)->setType(TypeFee::FEE_CHARGED_RATE)->setRate(5.0));
        $this->entityManager->flush();

        self::assertSame(0, $this->runCommand($transfer->getToken())->getStatusCode());
        $this->entityManager->clear();
        $storedTransfer = $this->entityManager->getRepository(Transfer::class)->findOneBy(['token' => $transfer->getToken()]);
        $fee = $this->entityManager->getRepository(Fee::class)->findOneBy(['transfer' => $storedTransfer]);

        self::assertSame(5.0, $fee?->getRate());
        self::assertSame('5.00', number_format($fee?->getAmount() ?? 0, 2, '.', ''));
        self::assertSame('45.00', number_format((float) ($this->entityManager->find(Account::class, $this->account->getId())?->getBalance() ?? 0), 2, '.', ''));
    }

    #[Test]
    public function freeFeeDebitsOnlyTheWithdrawalAmount(): void
    {
        $transfer = $this->pendingWithdrawal('CMD-WIT-FREE', '100.00');
        $this->entityManager->persist((new Fee())->setTransfer($transfer)->setType(TypeFee::FREE_CHARGED)->setAmount(0.0));
        $this->entityManager->flush();

        self::assertSame(0, $this->runCommand($transfer->getToken())->getStatusCode());
        $this->entityManager->clear();
        $fee = $this->entityManager->getRepository(Fee::class)->findOneBy([
            'transfer' => $this->entityManager->getRepository(Transfer::class)->findOneBy(['token' => $transfer->getToken()]),
        ]);

        self::assertSame(TypeFee::FREE_CHARGED, $fee?->getType());
        self::assertSame('50.00', number_format((float) ($this->entityManager->find(Account::class, $this->account->getId())?->getBalance() ?? 0), 2, '.', ''));
    }

    #[Test]
    public function rejectsUnknownWrongTypeAndInvalidAmount(): void
    {
        $wrongType = $this->pendingWithdrawal('CMD-WIT-TYPE', '10.00')->setType(TypeTransfer::DEPOSIT);
        $invalid = $this->pendingWithdrawal('CMD-WIT-INVALID', '0.00');
        $this->entityManager->flush();

        self::assertSame(1, $this->runCommand('unknown-withdrawal')->getStatusCode());
        self::assertSame(1, $this->runCommand($wrongType->getToken())->getStatusCode());
        self::assertSame(1, $this->runCommand($invalid->getToken())->getStatusCode());
    }

    #[Test]
    public function rejectsWithdrawalWithoutASourceAccount(): void
    {
        $transfer = $this->pendingWithdrawal('CMD-WIT-NO-SOURCE', '10.00')->setSenderAccount(null);
        $this->entityManager->flush();

        self::assertSame(1, $this->runCommand($transfer->getToken())->getStatusCode());
    }

    #[Test]
    public function rejectsSuspendedSourceAccount(): void
    {
        $suspended = $this->pendingWithdrawal('CMD-WIT-SUSPENDED', '10.00');
        $this->account->setStatus(StatusAccount::SUSPENDED);
        $this->entityManager->flush();
        self::assertSame(1, $this->runCommand($suspended->getToken())->getStatusCode());
    }

    #[Test]
    public function rejectsClosedSourceAccount(): void
    {
        $closed = $this->pendingWithdrawal('CMD-WIT-CLOSED', '10.00');
        $this->account->setStatus(StatusAccount::CLOSED);
        $this->entityManager->flush();

        self::assertSame(1, $this->runCommand($closed->getToken())->getStatusCode());
    }

    #[Test]
    public function rejectsSourceAccountWithMismatchedCurrency(): void
    {
        $otherCurrency = (new \App\Entity\Currency())
            ->setCode('EUR')->setName('Euro')->setSymbol('€');
        $this->entityManager->persist($otherCurrency);
        $transfer = $this->pendingWithdrawal('CMD-WIT-CURRENCY', '10.00')->setCurrency($otherCurrency);
        $this->entityManager->flush();

        self::assertSame(1, $this->runCommand($transfer->getToken())->getStatusCode());
    }

    #[Test]
    public function rejectsInsufficientBalanceIncludingFees(): void
    {
        $this->account->setBalance('105.00');
        $transfer = $this->pendingWithdrawal('CMD-WIT-INSUFFICIENT', '100.00');
        $this->entityManager->persist((new Fee())->setTransfer($transfer)->setType(TypeFee::FEE_CHARGED_FIXED)->setAmount(10.0));
        $this->entityManager->flush();

        self::assertSame(1, $this->runCommand($transfer->getToken())->getStatusCode());
        self::assertSame('105.00', number_format((float) $this->account->getBalance(), 2, '.', ''));
    }

    #[Test]
    public function rejectsAlreadyExecutedWithdrawal(): void
    {
        $transfer = $this->pendingWithdrawal('CMD-WIT-DUPLICATE', '10.00');
        $this->entityManager->flush();

        self::assertSame(0, $this->runCommand($transfer->getToken())->getStatusCode());
        self::assertSame(1, $this->runCommand($transfer->getToken())->getStatusCode());
    }

    #[Test]
    public function rollsBackWhenFeeSystemAccountIsUnavailable(): void
    {
        $transfer = $this->pendingWithdrawal('CMD-WIT-ROLLBACK', '100.00');
        $this->entityManager->persist((new Fee())->setTransfer($transfer)->setType(TypeFee::FEE_CHARGED_FIXED)->setAmount(10.0));
        $this->systemAccount->setStatus(StatusAccount::SUSPENDED);
        $this->entityManager->flush();

        self::assertSame(1, $this->runCommand($transfer->getToken())->getStatusCode());
        $this->entityManager->clear();
        $storedTransfer = $this->entityManager->getRepository(Transfer::class)->findOneBy(['token' => $transfer->getToken()]);

        self::assertSame(StatusTransfer::PENDING, $storedTransfer?->getStatus());
        self::assertSame('150.00', number_format((float) ($this->entityManager->find(Account::class, $this->account->getId())?->getBalance() ?? 0), 2, '.', ''));
        self::assertSame([], $this->entityManager->getRepository(Operation::class)->findBy(['transfer' => $storedTransfer]));
    }

    private function runCommand(?string $identifier = null, array $options = [], ?TransferExecutionDelay $delay = null): CommandTester
    {
        $command = new \App\Command\ExecuteWithdrawalCommand(static::getContainer()->get(WithdrawalExecutor::class), $delay);
        $tester = new CommandTester($command);
        $input = $options;
        if ($identifier !== null) {
            $input['token-or-reference'] = $identifier;
        }
        $tester->execute($input);
        return $tester;
    }

    private function pendingWithdrawal(string $reference, string $amount): Transfer
    {
        $now = new DateTimeImmutable();
        $transfer = (new Transfer())
            ->setToken('token-' . strtolower($reference))
            ->setReference($reference)
            ->setSenderAccount($this->account)
            ->setAmount($amount)
            ->setCurrency($this->currency)
            ->setReceivedAmount($amount)
            ->setReceivedCurrency($this->currency)
            ->setExchangeRate('1.0000000000')
            ->setType(TypeTransfer::WITHDRAWAL)
            ->setStatus(StatusTransfer::PENDING)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $this->entityManager->persist($transfer);
        return $transfer;
    }
}
