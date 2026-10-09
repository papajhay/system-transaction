<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Entity\Transfer;
use App\Enum\TypeFee;
use App\Enum\TypeTransfer;
use App\Form\TransferType;
use App\Tests\Feature\Transactions\TransactionTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class TransferTypeTest extends TransactionTestCase
{
    #[Test]
    #[DataProvider('feeChoices')]
    public function itKeepsBothFeeFieldsAvailableForSubmission(
        TypeFee $feeType,
        float $feeInput,
        float $amount,
    ): void {
        $transfer = (new Transfer())->setType(TypeTransfer::DEPOSIT);
        $form = $this->createTransferForm($transfer);

        $submittedData = [
            'type' => TypeTransfer::DEPOSIT->value,
            'account_number' => (string) $this->account->getId(),
            'amount' => $amount,
            'description' => '',
            'feeType' => $feeType->value,
        ];
        if ($feeType === TypeFee::FEE_CHARGED_FIXED) {
            $submittedData['feeAmount'] = $feeInput;
        } elseif ($feeType === TypeFee::FEE_CHARGED_RATE) {
            $submittedData['feeRate'] = $feeInput;
        }
        $form->submit($submittedData);

        self::assertTrue($form->isSynchronized());
        self::assertSame($feeType, $transfer->getFeeType());
        self::assertTrue($form->has('feeAmount'));
        self::assertTrue($form->has('feeRate'));
        if ($feeType === TypeFee::FEE_CHARGED_FIXED) {
            self::assertSame(number_format($feeInput, 2, '.', ''), $form->get('feeAmount')->getViewData());
        }
        if ($feeType === TypeFee::FEE_CHARGED_RATE) {
            self::assertSame(number_format($feeInput, 2, '.', ''), $form->get('feeRate')->getViewData());
        }
        self::assertTrue($form->has('account_number'));
    }

    #[Test]
    public function itAddsTheFeeAmountFieldWhenExistingTransferIsCharged(): void
    {
        $transfer = (new Transfer())
            ->setType(TypeTransfer::DEPOSIT)
            ->setFeeType(TypeFee::FEE_CHARGED_RATE)
            ->setFeeRate(20.0)
            ->setFeeAmount(20.0);
        $transfer->setAmount('100.00');

        $form = $this->createTransferForm($transfer);

        self::assertTrue($form->has('feeAmount'));
        self::assertTrue($form->has('feeRate'));
        self::assertSame('20.00', $form->get('feeRate')->getViewData());
    }

    #[Test]
    public function itRejectsNonPositiveAmounts(): void
    {
        $form = $this->createTransferForm((new Transfer())->setType(TypeTransfer::DEPOSIT));

        $form->submit([
            'type' => TypeTransfer::DEPOSIT->value,
            'account_number' => (string) $this->account->getId(),
            'amount' => 0,
            'description' => '',
            'feeType' => TypeFee::FREE_CHARGED->value,
        ]);

        self::assertFalse($form->isValid());
        self::assertNotEmpty($form->get('amount')->getErrors());
    }

    #[Test]
    public function itRejectsInvalidFeeValues(): void
    {
        $form = $this->createTransferForm((new Transfer())->setType(TypeTransfer::DEPOSIT));

        $form->submit([
            'type' => TypeTransfer::DEPOSIT->value,
            'account_number' => (string) $this->account->getId(),
            'amount' => 1000,
            'description' => '',
            'feeType' => TypeFee::FEE_CHARGED_RATE->value,
            'feeRate' => 100.01,
        ]);

        self::assertFalse($form->isValid());
        self::assertNotEmpty($form->get('feeRate')->getErrors());
    }

    /** @return iterable<string, array{TypeFee, float, float}> */
    public static function feeChoices(): iterable
    {
        yield 'fixed fee' => [TypeFee::FEE_CHARGED_FIXED, 25.5, 1000.0];
        yield 'no fee' => [TypeFee::FREE_CHARGED, 0.0, 1000.0];
        yield 'fee rate' => [TypeFee::FEE_CHARGED_RATE, 12.5, 1000.0];
    }

    private function createTransferForm(Transfer $transfer): FormInterface
    {
        return static::getContainer()
            ->get(FormFactoryInterface::class)
            ->create(TransferType::class, $transfer, ['csrf_protection' => false]);
    }
}
