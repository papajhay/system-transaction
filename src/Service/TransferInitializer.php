<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Entity\Transfer;
use App\Enum\StatusTransfer;
use App\Enum\TypeFee;
use App\Enum\TypeTransfer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class TransferInitializer
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @param array<string, mixed> $data */
    public function initialize(Transfer $transfer, TypeTransfer $type, array $data): void
    {
        $amount = $this->amount($data['amount'] ?? null);
        $description = $data['description'] ?? null;
        $description = $description === '' ? null : (is_string($description) ? $description : null);
        $feeType = is_string($data['feeType'] ?? null)
            ? TypeFee::tryFrom($data['feeType']) ?? TypeFee::FREE_CHARGED
            : TypeFee::FREE_CHARGED;
        $feeRate = $feeType === TypeFee::FEE_CHARGED_RATE
            ? $transfer->getFeeRate()
            : 0.0;
        $feeAmount = match ($feeType) {
            TypeFee::FEE_CHARGED_FIXED => $transfer->getFeeAmount(),
            TypeFee::FEE_CHARGED_RATE => round((float) $amount * $feeRate / 100, 2, PHP_ROUND_HALF_UP),
            TypeFee::FREE_CHARGED => 0.0,
        };
        $now = new DateTimeImmutable();

        $transfer->setType($type)
            ->setAmount($amount)
            ->setDescription($description)
            ->setFeeType($feeType)
            ->setFeeRate($feeRate)
            ->setFeeAmount($feeAmount)
            ->setToken(Uuid::v4()->toRfc4122())
            ->setReference($this->reference($type))
            ->setStatus(StatusTransfer::PENDING)
            ->setReceivedAmount($amount)
            ->setExchangeRate('1.0000000000')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        if ($type === TypeTransfer::TRANSFER) {
            $sender = $this->findAccount($data['from_account_number'] ?? null, 'source');
            $receiver = $this->findAccount($data['to_account_number'] ?? null, 'destination');

            $transfer->setSenderAccount($sender)
                ->setReceiverAccount($receiver)
                ->setCurrency($sender->getCurrency())
                ->setReceivedCurrency($receiver->getCurrency());

            return;
        }

        $account = $this->findAccount($data['account_number'] ?? null, '');
        $transfer->setCurrency($account->getCurrency())
            ->setReceivedCurrency($account->getCurrency());

        if ($type === TypeTransfer::DEPOSIT) {
            $transfer->setReceiverAccount($account);
        } else {
            $transfer->setSenderAccount($account);
        }
    }

    private function findAccount(mixed $accountNumber, string $role): Account
    {
        if ($accountNumber instanceof Account) {
            return $accountNumber;
        }

        if (!is_string($accountNumber) || trim($accountNumber) === '') {
            throw new \InvalidArgumentException(sprintf(
                'The %s account number is required.',
                $role === '' ? 'account' : $role,
            ));
        }

        $accountRepository = $this->entityManager->getRepository(Account::class);
        $account = ctype_digit(trim($accountNumber))
            ? $accountRepository->find((int) $accountNumber)
            : $accountRepository->findOneBy(['accountNumber' => trim($accountNumber)]);

        if (!$account instanceof Account) {
            throw new \InvalidArgumentException(sprintf(
                'The %s account "%s" does not exist.',
                $role === '' ? 'account' : $role,
                $accountNumber,
            ));
        }

        return $account;
    }

    private function amount(mixed $amount): string
    {
        $isNumericValue = is_string($amount)
            || is_int($amount)
            || is_float($amount);

        if (!$isNumericValue || !is_numeric($amount) || (float) $amount <= 0) {
            throw new \InvalidArgumentException('The amount must be greater than zero.');
        }

        return number_format((float) $amount, 6, '.', '');
    }

    private function reference(TypeTransfer $type): string
    {
        return match ($type) {
            TypeTransfer::TRANSFER => 'TRF-',
            TypeTransfer::DEPOSIT => 'DEP-',
            TypeTransfer::WITHDRAWAL => 'WIT-',
        } . strtoupper(bin2hex(random_bytes(5)));
    }
}
