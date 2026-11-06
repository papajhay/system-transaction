<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Entity\Transfer;
use App\Enum\TypeFee;
use App\Enum\TypeTransfer;

final class TransferBalanceValidationService
{
    public function __construct(
        private readonly AccountValidationService $accountValidation,
        private readonly FeeCalculator $feeCalculator,
    ) {
    }

    /** @param array<string, mixed> $data
     *  @return array{valid: bool, code: string, available: string, requested: string, fees: string, total: string, missing: string}
     */
    public function validate(array $data): array
    {
        $type = is_string($data['type'] ?? null) ? TypeTransfer::tryFrom($data['type']) : null;
        if ($type === null || $type === TypeTransfer::DEPOSIT) {
            return $this->result(true, 'not_required');
        }

        $accountNumber = $type === TypeTransfer::TRANSFER
            ? ($data['source_account_number'] ?? null)
            : ($data['account_number'] ?? null);
        $accountResult = $this->accountValidation->validateAccountNumber($accountNumber);
        $account = $accountResult['account'];
        if (!$accountResult['valid'] || !$account instanceof Account) {
            return $this->result(false, $accountResult['code']);
        }

        $amount = $this->cents($data['amount'] ?? null);
        if ($amount <= 0) {
            return $this->result(false, 'invalid_amount');
        }

        $transfer = (new Transfer())
            ->setFeeType($this->feeType($data['feeType'] ?? null))
            ->setFeeAmount($this->floatValue($data['feeAmount'] ?? 0))
            ->setFeeRate($this->floatValue($data['feeRate'] ?? 0));
        $fee = $this->feeCalculator->calculateForTransfer($amount, $transfer);
        if ($type === TypeTransfer::TRANSFER) {
            $destination = $this->accountValidation->validateAccountNumber($data['destination_account_number'] ?? null)['account'];
            if ($destination instanceof Account && $destination->getCurrency()?->getId() !== $account->getCurrency()?->getId()) {
                $fee = ['amount' => 0, 'type' => TypeFee::FREE_CHARGED, 'rate' => null];
            }
        }
        $available = $this->cents($account->getBalance());
        $total = $amount + $fee['amount'];

        return $this->result($available >= $total, 'insufficient_balance', $available, $amount, $fee['amount'], $total);
    }

    /** @return array{valid: bool, code: string, available: string, requested: string, fees: string, total: string, missing: string} */
    private function result(bool $valid, string $code, int $available = 0, int $requested = 0, int $fees = 0, int $total = 0): array
    {
        return [
            'valid' => $valid,
            'code' => $valid ? 'valid' : $code,
            'available' => $this->money($available),
            'requested' => $this->money($requested),
            'fees' => $this->money($fees),
            'total' => $this->money($total),
            'missing' => $this->money(max(0, $total - $available)),
        ];
    }

    private function feeType(mixed $value): TypeFee
    {
        return is_string($value) && TypeFee::tryFrom($value) instanceof TypeFee
            ? TypeFee::from($value)
            : TypeFee::FREE_CHARGED;
    }

    private function floatValue(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function cents(mixed $value): int
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return 0;
        }
        $value = trim((string) $value);
        if (!preg_match('/^\d+(?:\.\d{1,6})?$/', $value, $matches)) {
            return 0;
        }
        $parts = explode('.', $matches[0], 2);
        $fraction = str_pad($parts[1] ?? '', 3, '0');

        return ((int) $parts[0] * 100) + (int) substr($fraction, 0, 2) + ((int) $fraction[2] >= 5 ? 1 : 0);
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
