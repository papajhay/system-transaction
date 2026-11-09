<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Entity\Account;
use App\Entity\Transfer;
use App\Enum\StatusTransfer;
use App\Enum\TypeFee;
use App\Enum\TypeTransfer;
use App\Form\TransferType;
use App\Service\TransferExecutor;
use App\Service\TransferInitializer;
use App\Tests\Feature\Transactions\TransactionTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Form\FormFactoryInterface;

final class TransferFeeLifecycleTest extends TransactionTestCase
{
    #[Test]
    public function consecutiveFormTransfersKeepTheirOwnFixedFees(): void
    {
        $this->account->setBalance('2000.00');
        $this->entityManager->flush();
        $receiver = $this->receiver();
        $cases = [
            ['790.00', 27.0],
            ['550.00', 17.0],
            ['250.00', 9.0],
        ];

        foreach ($cases as [$amount, $feeAmount]) {
            $transfer = new Transfer();
            $form = static::getContainer()->get(FormFactoryInterface::class)->create(TransferType::class, $transfer, [
                'csrf_protection' => false,
                'dynamic_transfer_form' => true,
            ]);
            $form->submit([
                'type' => TypeTransfer::TRANSFER->value,
                'from_account_number' => (string) $this->account->getId(),
                'to_account_number' => (string) $receiver->getId(),
                'amount' => $amount,
                'feeType' => TypeFee::FEE_CHARGED_FIXED->value,
                'feeAmount' => $feeAmount,
                'feeRate' => 0,
            ]);

            self::assertTrue($form->isValid());
            self::assertSame($feeAmount, $transfer->getFeeAmount());

            static::getContainer()->get(TransferInitializer::class)->initialize($transfer, TypeTransfer::TRANSFER, [
                'from_account_number' => (string) $this->account->getId(),
                'to_account_number' => (string) $receiver->getId(),
                'amount' => $amount,
                'feeType' => TypeFee::FEE_CHARGED_FIXED->value,
            ]);
            $this->entityManager->persist($transfer);
            $this->entityManager->flush();

            self::assertSame($feeAmount, $transfer->getFee()?->getAmount());
            $before = [
                'reference' => $transfer->getReference(),
                'amount' => $transfer->getAmount(),
                'description' => $transfer->getDescription(),
                'feeType' => $transfer->getFeeType(),
                'feeRate' => $transfer->getFeeRate(),
                'feeAmount' => $transfer->getFeeAmount(),
                'sender' => $transfer->getSenderAccount()?->getId(),
                'receiver' => $transfer->getReceiverAccount()?->getId(),
            ];
            static::getContainer()->get(TransferExecutor::class)->execute($transfer->getToken());
            $this->entityManager->clear();

            $stored = $this->entityManager->getRepository(Transfer::class)->find($transfer->getId());
            self::assertSame(StatusTransfer::COMPLETED, $stored?->getStatus());
            self::assertSame($feeAmount, $stored?->getFeeAmount());
            self::assertSame($feeAmount, $stored?->getFee()?->getAmount());
            self::assertSame($before['reference'], $stored?->getReference());
            self::assertSame(
                number_format((float) $before['amount'], 6, '.', ''),
                number_format((float) ($stored?->getAmount() ?? 0), 6, '.', ''),
            );
            self::assertSame($before['description'], $stored?->getDescription());
            self::assertSame($before['feeType'], $stored?->getFeeType());
            self::assertSame($before['feeRate'], $stored?->getFeeRate());
            self::assertSame($before['sender'], $stored?->getSenderAccount()?->getId());
            self::assertSame($before['receiver'], $stored?->getReceiverAccount()?->getId());
        }
    }

    #[Test]
    public function creationFeeAmountIsPreservedForFixedRateAndFreeTransfers(): void
    {
        $this->account->setBalance('2000.00');
        $this->entityManager->flush();
        $receiver = $this->receiver();

        foreach ([
            [TypeFee::FEE_CHARGED_FIXED, 20.0, 0.0, 20.0],
            [TypeFee::FEE_CHARGED_RATE, 10.0, 10.0, 10.0],
            [TypeFee::FREE_CHARGED, 0.0, 0.0, 0.0],
        ] as [$feeType, $feeAmount, $feeRate, $expectedFee]) {
            $transfer = (new Transfer())
                ->setFeeAmount($feeAmount)
                ->setFeeRate($feeRate);

            static::getContainer()->get(TransferInitializer::class)->initialize(
                $transfer,
                TypeTransfer::TRANSFER,
                [
                    'from_account_number' => (string) $this->account->getId(),
                    'to_account_number' => (string) $receiver->getId(),
                    'amount' => '100.00',
                    'feeType' => $feeType->value,
                ],
            );
            $this->entityManager->persist($transfer);
            $this->entityManager->flush();

            static::getContainer()->get(TransferExecutor::class)->execute($transfer->getToken());
            $this->entityManager->clear();

            $stored = $this->entityManager->getRepository(Transfer::class)->find($transfer->getId());
            self::assertSame(StatusTransfer::COMPLETED, $stored?->getStatus());
            self::assertSame($expectedFee, $stored?->getFeeAmount());
            self::assertSame($expectedFee, $stored?->getFee()?->getAmount());
            self::assertSame($stored?->getFeeAmount(), $stored?->getFee()?->getAmount());
        }
    }

    private function receiver(): Account
    {
        $receiver = (new Account())
            ->setAccountNumber('FEE-LIFECYCLE-RECEIVER')
            ->setBalance('0.00')
            ->setCurrency($this->currency)
            ->setStatus(\App\Enum\StatusAccount::ACTIVE)
            ->setType(\App\Enum\TypeAccount::USER)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($receiver);
        $this->entityManager->flush();

        return $receiver;
    }
}
