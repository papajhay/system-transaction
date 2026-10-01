<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Entity\Transfer;
use App\Enum\TypeFee;
use App\Form\TransferType;
use App\Enum\TypeTransfer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

final class TransferTypeTest extends TypeTestCase
{
    #[Test]
    #[DataProvider('feeChoices')]
    public function itCalculatesAndStoresTheSelectedFee(
        TypeFee $feeType,
        float $rate,
        float $amount,
        float $expectedFee,
    ): void {
        $transfer = (new Transfer())->setType(TypeTransfer::DEPOSIT);
        $form = $this->factory->create(TransferType::class, $transfer);

        $form->submit([
            'type' => TypeTransfer::DEPOSIT->value,
            'account_number' => 'ACC-001',
            'amount' => $amount,
            'description' => '',
            'feeType' => $feeType->value,
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertSame($feeType, $transfer->getFeeType());
        self::assertSame($rate, $transfer->getFeeRate());
        self::assertSame($expectedFee, $transfer->getFeeAmount());
        self::assertSame($feeType === TypeFee::FREE_CHARGED, !$form->has('feeAmount'));
        if ($feeType !== TypeFee::FREE_CHARGED) {
            self::assertSame(number_format($expectedFee, 2, '.', ''), $form->get('feeAmount')->getViewData());
        }
        self::assertTrue($form->has('account_number'));
    }

    #[Test]
    public function itAddsTheFeeAmountFieldWhenExistingTransferIsCharged(): void
    {
        $transfer = (new Transfer())
            ->setType(TypeTransfer::DEPOSIT)
            ->setFeeType(TypeFee::FEE_CHARGED_RATE)
            ->setFeeAmount(20.0);
        $transfer->setAmount('100.00');

        $form = $this->factory->create(TransferType::class, $transfer);

        self::assertTrue($form->has('feeAmount'));
        self::assertSame('20.00', $form->get('feeAmount')->getViewData());
    }

    #[Test]
    public function itRejectsNonPositiveAmounts(): void
    {
        $form = $this->factory->create(
            TransferType::class,
            (new Transfer())->setType(TypeTransfer::DEPOSIT),
        );

        $form->submit([
            'type' => TypeTransfer::DEPOSIT->value,
            'account_number' => 'ACC-001',
            'amount' => 0,
            'description' => '',
            'feeType' => TypeFee::FREE_CHARGED->value,
        ]);

        self::assertFalse($form->isValid());
        self::assertNotEmpty($form->get('amount')->getErrors());
    }

    /** @return iterable<string, array{TypeFee, float, float, float}> */
    public static function feeChoices(): iterable
    {
        yield 'fixed fee' => [TypeFee::FEE_CHARGED_FIXED, 0.01, 1000.0, 0.1];
        yield 'no fee' => [TypeFee::FREE_CHARGED, 0.0, 1000.0, 0.0];
        yield 'fee rate' => [TypeFee::FEE_CHARGED_RATE, 20.0, 1000.0, 200.0];
    }

    protected function getExtensions(): array
    {
        return [
            new PreloadedExtension([new TransferType()], []),
            new ValidatorExtension(Validation::createValidator()),
        ];
    }
}
