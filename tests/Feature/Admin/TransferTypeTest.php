<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Entity\Transfer;
use App\Enum\TypeFee;
use App\Enum\TypeTransfer;
use App\Form\TransferType;
use App\Tests\Feature\Transactions\TransactionTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Form\FormFactoryInterface;

final class TransferTypeTest extends TransactionTestCase
{
    #[Test]
    public function itRendersAllAccountControlsForTheDynamicNewForm(): void
    {
        $form = static::getContainer()
            ->get(FormFactoryInterface::class)
            ->create(TransferType::class, new Transfer(), [
                'csrf_protection' => false,
                'dynamic_transfer_form' => true,
            ]);

        self::assertTrue($form->has('account_number'));
        self::assertTrue($form->has('from_account_number'));
        self::assertTrue($form->has('to_account_number'));
        self::assertSame(
            'transfer-type',
            $form->get('type')->getConfig()->getOption('attr')['data-controller'],
        );
        self::assertSame(
            'true',
            $form->get('type')->getConfig()->getOption('row_attr')['data-transfer-type-selector'],
        );
        self::assertTrue($form->get('type')->getConfig()->getOption('row_attr')['hidden']);
    }

    #[Test]
    #[DataProvider('dynamicTransferCases')]
    public function itBuildsTheSelectedAccountFieldsAndFeeFields(
        TypeTransfer $type,
        TypeFee $feeType,
    ): void {
        $form = static::getContainer()
            ->get(FormFactoryInterface::class)
            ->create(TransferType::class, new Transfer(), [
                'csrf_protection' => false,
                'dynamic_transfer_form' => true,
            ]);

        $submittedData = [
            'type' => $type->value,
            'amount' => 10,
            'description' => '',
            'feeType' => $feeType->value,
        ];

        if ($type === TypeTransfer::TRANSFER) {
            $submittedData['from_account_number'] = (string) $this->account->getId();
            $submittedData['to_account_number'] = (string) $this->systemAccount->getId();
        } else {
            $submittedData['account_number'] = (string) $this->account->getId();
        }

        if ($feeType === TypeFee::FEE_CHARGED_FIXED) {
            $submittedData['feeAmount'] = 2.5;
        } elseif ($feeType === TypeFee::FEE_CHARGED_RATE) {
            $submittedData['feeRate'] = 12.5;
        }

        $form->submit($submittedData);

        self::assertTrue($form->isValid(), json_encode(
            iterator_to_array($form->getErrors(true)),
            JSON_THROW_ON_ERROR,
        ));
        self::assertSame($type, $form->getData()->getType());
        self::assertTrue($form->has('feeAmount'));
        self::assertTrue($form->has('feeRate'));

        if ($type === TypeTransfer::TRANSFER) {
            self::assertTrue($form->has('from_account_number'));
            self::assertTrue($form->has('to_account_number'));
            self::assertFalse($form->has('account_number'));
        } else {
            self::assertTrue($form->has('account_number'));
            self::assertFalse($form->has('from_account_number'));
            self::assertFalse($form->has('to_account_number'));
        }
    }

    #[Test]
    public function itUsesAccountEntitiesAndDisplaysAccountNumbers(): void
    {
        $form = static::getContainer()
            ->get(FormFactoryInterface::class)
            ->create(TransferType::class, new Transfer(), ['csrf_protection' => false]);

        $form->submit([
            'type' => TypeTransfer::TRANSFER->value,
            'from_account_number' => (string) $this->account->getId(),
            'to_account_number' => (string) $this->systemAccount->getId(),
            'amount' => 10,
            'description' => '',
            'feeType' => TypeFee::FREE_CHARGED->value,
        ]);

        self::assertTrue($form->isValid(), json_encode(array_map(
            static fn ($field): array => array_map(
                static fn ($error): string => $error->getMessage(),
                iterator_to_array($field->getErrors()),
            ),
            iterator_to_array($form),
        ), JSON_THROW_ON_ERROR));
        self::assertSame($this->account, $form->get('from_account_number')->getData());
        self::assertSame($this->systemAccount, $form->get('to_account_number')->getData());
        self::assertSame(
            'accountNumber',
            $form->get('from_account_number')->getConfig()->getOption('choice_label'),
        );
    }

    #[Test]
    public function itRejectsTheSameSourceAndDestinationAccount(): void
    {
        $form = static::getContainer()
            ->get(FormFactoryInterface::class)
            ->create(TransferType::class, new Transfer(), ['csrf_protection' => false]);

        $form->submit([
            'type' => TypeTransfer::TRANSFER->value,
            'from_account_number' => (string) $this->account->getId(),
            'to_account_number' => (string) $this->account->getId(),
            'amount' => 10,
            'description' => '',
            'feeType' => TypeFee::FREE_CHARGED->value,
        ]);

        self::assertFalse($form->isValid());
        self::assertSame(
            'The source and destination accounts must be different.',
            (string) $form->get('to_account_number')->getErrors()[0]->getMessage(),
        );
    }

    /** @return iterable<string, array{TypeTransfer, TypeFee}> */
    public static function dynamicTransferCases(): iterable
    {
        foreach (TypeTransfer::cases() as $transferType) {
            foreach (TypeFee::cases() as $feeType) {
                yield $transferType->value . '-' . $feeType->name => [$transferType, $feeType];
            }
        }
    }
}
