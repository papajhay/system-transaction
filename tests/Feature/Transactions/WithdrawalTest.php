<?php

declare(strict_types=1);

namespace App\Tests\Feature\Transactions;

use App\Entity\Transfer;
use App\Enum\StatusTransfer;
use App\Enum\TypeOperation;
use App\Enum\TypeTransfer;
use PHPUnit\Framework\Attributes\Test;

final class WithdrawalTest extends TransactionTestCase
{
    #[Test]
    public function testAuthenticatedUserCanInitializeAWithdrawal(): void
    {
        $this->client->jsonRequest('POST', '/api/transactions/init-withdrawal', [
            'account_number' => $this->account->getAccountNumber(),
            'amount' => 100.00,
            'description' => 'ATM withdrawal',
        ]);

        self::assertResponseStatusCodeSame(201);
        $body = $this->jsonResponse();
        self::assertArrayHasKey('token', $body['data']);
        self::assertArrayHasKey('reference', $body['data']);

        $transfer = $this->entityManager->getRepository(Transfer::class)->findOneBy([
            'senderAccount' => $this->account,
            'type' => TypeTransfer::WITHDRAWAL,
            'status' => StatusTransfer::PENDING,
        ]);
        self::assertNotNull($transfer);
        self::assertSame('100.00', number_format((float) $transfer->getAmount(), 2, '.', ''));
        self::assertSame('ATM withdrawal', $transfer->getDescription());
    }

    #[Test]
    public function testAuthenticatedUserCanExecuteAWithdrawal(): void
    {
        $this->client->jsonRequest('POST', '/api/transactions/init-withdrawal', [
            'account_number' => $this->account->getAccountNumber(),
            'amount' => 100.00,
            'description' => 'ATM withdrawal',
        ]);
        $token = $this->jsonResponse()['data']['token'];

        $this->client->jsonRequest('POST', '/api/transactions/execute-withdrawal', ['token' => $token]);

        self::assertResponseStatusCodeSame(201);
        $body = $this->jsonResponse();
        self::assertSame('Withdrawal executed successfully', $body['message']);
        self::assertSame($token, $body['data']['token']);
        self::assertSame('completed', $body['data']['status']);

        $this->entityManager->clear();
        $account = $this->entityManager->find(\App\Entity\Account::class, $this->account->getId());
        self::assertSame('50.00', number_format((float) ($account?->getBalance()), 2, '.', ''));
        $operation = $this->entityManager->getRepository(\App\Entity\Operation::class)->findOneBy([
            'type' => TypeOperation::DEBIT,
        ]);
        self::assertNotNull($operation);
    }

    #[Test]
    public function testGuestCannotInitializeAWithdrawal(): void
    {
        $this->client->setServerParameter('HTTP_AUTHORIZATION', '');
        $this->client->jsonRequest('POST', '/api/transactions/init-withdrawal', [
            'account_number' => $this->account->getAccountNumber(), 'amount' => 100,
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function testWithdrawalAmountMustBePositive(): void
    {
        foreach ([0, -25.00] as $amount) {
            $this->client->jsonRequest('POST', '/api/transactions/init-withdrawal', [
                'account_number' => $this->account->getAccountNumber(), 'amount' => $amount,
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertTrue(array_reduce($this->jsonResponse()['violations'] ?? [],
                static fn (bool $found, array $v): bool => $found || ($v['propertyPath'] ?? null) === 'amount', false));
        }
    }

    #[Test]
    public function testWithdrawalAccountMustExist(): void
    {
        $this->client->jsonRequest('POST', '/api/transactions/init-withdrawal', [
            'account_number' => 'NON-EXISTENT-ACCOUNT', 'amount' => 100,
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('account_number', $this->jsonResponse()['violations'][0]['propertyPath']);
    }

    #[Test]
    public function testCannotExecuteWithdrawalTwiceWithSameToken(): void
    {
        $this->client->jsonRequest('POST', '/api/transactions/init-withdrawal', [
            'account_number' => $this->account->getAccountNumber(), 'amount' => 100,
        ]);
        $token = $this->jsonResponse()['data']['token'];
        $this->client->jsonRequest('POST', '/api/transactions/execute-withdrawal', ['token' => $token]);
        self::assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', '/api/transactions/execute-withdrawal', ['token' => $token]);
        self::assertResponseStatusCodeSame(400);
    }

    /** @return array<string, mixed> */
    private function jsonResponse(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
