<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Entity\Transfer;
use App\Enum\TypeFee;
use App\Enum\TypeTransfer;
use App\Form\TransferType;
use App\Tests\Feature\Transactions\TransactionTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Form\FormFactoryInterface;

final class TransferTypeTest extends TransactionTestCase
{
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
}
