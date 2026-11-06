<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Enum\StatusAccount;
use Doctrine\ORM\EntityManagerInterface;

final class AccountValidationService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @return array{valid: bool, code: string, account: ?Account} */
    public function validate(mixed $value): array
    {
        $account = $value instanceof Account ? $value : $this->findSubmittedAccount($value);

        return $this->validateAccount($account);
    }

    /** @return array{valid: bool, code: string, account: ?Account} */
    public function validateAccountNumber(mixed $value): array
    {
        $account = is_string($value) && trim($value) !== ''
            ? $this->entityManager->getRepository(Account::class)->findOneBy(['accountNumber' => trim($value)])
            : null;

        return $this->validateAccount($account instanceof Account ? $account : null);
    }

    /** @return array{valid: bool, code: string, account: ?Account} */
    private function validateAccount(?Account $account): array
    {
        if (!$account instanceof Account) {
            return ['valid' => false, 'code' => 'not_found', 'account' => null];
        }
        if ($account->getStatus() === StatusAccount::CLOSED) {
            return ['valid' => false, 'code' => 'closed', 'account' => $account];
        }
        if ($account->getStatus() !== StatusAccount::ACTIVE) {
            return ['valid' => false, 'code' => 'inactive', 'account' => $account];
        }

        return ['valid' => true, 'code' => 'active', 'account' => $account];
    }

    private function findSubmittedAccount(mixed $value): ?Account
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $repository = $this->entityManager->getRepository(Account::class);
        $value = trim($value);

        return ctype_digit($value)
            ? $repository->find((int) $value)
            : $repository->findOneBy(['accountNumber' => $value]);
    }
}
