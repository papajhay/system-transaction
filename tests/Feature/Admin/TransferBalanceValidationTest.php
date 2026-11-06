<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Enum\TypeFee;
use App\Enum\TypeTransfer;
use App\Service\TransferBalanceValidationService;
use App\Tests\Feature\Transactions\TransactionTestCase;
use PHPUnit\Framework\Attributes\Test;

final class TransferBalanceValidationTest extends TransactionTestCase
{
    #[Test]
    public function acceptsTransferBelowAvailableBalance(): void
    {
        $result = $this->validate(TypeTransfer::TRANSFER, '100.00');

        self::assertTrue($result['valid']);
        self::assertSame('150.00', $result['available']);
        self::assertSame('100.00', $result['total']);
    }

    #[Test]
    public function acceptsTransferEqualToAvailableBalanceWithoutFees(): void
    {
        $result = $this->validate(TypeTransfer::TRANSFER, '150.00');

        self::assertTrue($result['valid']);
        self::assertSame('0.00', $result['missing']);
    }

    #[Test]
    public function rejectsTransferAboveAvailableBalance(): void
    {
        $result = $this->validate(TypeTransfer::TRANSFER, '150.01');

        self::assertFalse($result['valid']);
        self::assertSame('insufficient_balance', $result['code']);
        self::assertSame('0.01', $result['missing']);
    }

    #[Test]
    public function rejectsTransferWhenFeesExceedAvailableBalance(): void
    {
        $result = $this->validate(TypeTransfer::TRANSFER, '145.00', [
            'feeType' => TypeFee::FEE_CHARGED_FIXED->value,
            'feeAmount' => '10.00',
        ]);

        self::assertFalse($result['valid']);
        self::assertSame('10.00', $result['fees']);
        self::assertSame('155.00', $result['total']);
        self::assertSame('5.00', $result['missing']);
    }

    #[Test]
    public function validatesWithdrawalAgainstTheDebitedAccount(): void
    {
        $result = $this->validate(TypeTransfer::WITHDRAWAL, '150.00');

        self::assertTrue($result['valid']);
    }

    #[Test]
    public function doesNotApplyBalanceValidationToDeposits(): void
    {
        $result = $this->validate(TypeTransfer::DEPOSIT, '999999.00');

        self::assertTrue($result['valid']);
        self::assertSame('valid', $result['code']);
    }

    /** @param array<string, mixed> $overrides
     *  @return array{valid: bool, code: string, available: string, requested: string, fees: string, total: string, missing: string}
     */
    private function validate(TypeTransfer $type, string $amount, array $overrides = []): array
    {
        return static::getContainer()->get(TransferBalanceValidationService::class)->validate(array_merge([
            'type' => $type->value,
            'account_number' => $this->account->getAccountNumber(),
            'source_account_number' => $this->account->getAccountNumber(),
            'amount' => $amount,
            'feeType' => TypeFee::FREE_CHARGED->value,
            'feeAmount' => '0',
            'feeRate' => '0',
        ], $overrides));
    }
}
