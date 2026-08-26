<?php

declare(strict_types=1);

namespace App\Tests\Feature\Transactions;

use App\Entity\Transfer;
use App\Enum\StatusTransfer;
use App\Enum\TypeTransfer;
use PHPUnit\Framework\Attributes\Test;

final class DepositTest extends TransactionTestCase
{
    #[Test]
    public function testAuthenticatedUserCanInitializeADeposit(): void
    {
        $this->client->jsonRequest('POST', '/api/transactions/init-deposit', [
            'account_number' => $this->account->getAccountNumber(),
            'amount' => 150.00,
            'description' => 'Initial deposit',
        ]);

        self::assertResponseStatusCodeSame(201);
        $body = $this->responseJson();
        self::assertArrayHasKey('status_code', $body);
        self::assertArrayHasKey('message', $body);
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('token', $body['data']);
        self::assertArrayHasKey('reference', $body['data']);

        $this->assertTransferExists(
            receiverAccountId: $this->account->getId(),
            amount: '150.000000',
            type: TypeTransfer::DEPOSIT,
            status: StatusTransfer::PENDING,
            description: 'Initial deposit'
        );
    }

    #[Test]
    public function testAuthenticatedUserCanExecuteADepositWithToken(): void
    {
        $this->client->jsonRequest('POST', '/api/transactions/init-deposit', [
            'account_number' => $this->account->getAccountNumber(),
            'amount' => 150.00,
            'description' => 'Initial deposit',
        ]);

        self::assertResponseStatusCodeSame(201);
        $token = $this->responseJson()['data']['token'];

        $this->client->jsonRequest('POST', '/api/transactions/execute-deposit', [
            'token' => $token,
        ]);

        self::assertResponseStatusCodeSame(201);
        $body = $this->responseJson();
        self::assertSame(201, $body['status_code']);
        self::assertSame('Deposit executed successfully', $body['message']);
        self::assertSame($token, $body['data']['token']);
        self::assertSame('completed', $body['data']['status']);

        $this->assertTransferExists(
            receiverAccountId: $this->account->getId(),
            amount: '150.000000',
            type: TypeTransfer::DEPOSIT,
            status: StatusTransfer::COMPLETED
        );
    }

    #[Test]
    public function testGuestCannotInitializeADeposit(): void
    {
        $this->client->setServerParameter('HTTP_AUTHORIZATION', '');
        $this->client->jsonRequest('POST', '/api/transactions/init-deposit', [
            'account_number' => $this->account->getAccountNumber(),
            'amount' => 150.00,
            'description' => 'Initial deposit',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function testDepositAmountMustBePositive(): void
    {
        $payload = [
            'account_number' => $this->account->getAccountNumber(),
            'amount' => 0,
            'description' => 'Initial deposit',
        ];

        $this->client->jsonRequest('POST', '/api/transactions/init-deposit', $payload);
        self::assertResponseStatusCodeSame(422);
        $this->assertValidationError('amount');

        $payload['amount'] = -50.00;
        $this->client->jsonRequest('POST', '/api/transactions/init-deposit', $payload);
        self::assertResponseStatusCodeSame(422);
        $this->assertValidationError('amount');
    }

    #[Test]
    public function testAccountMustExist(): void
    {
        $this->client->jsonRequest('POST', '/api/transactions/init-deposit', [
            'account_number' => 'NON-EXISTENT-ACCOUNT',
            'amount' => 150.00,
            'description' => 'Initial deposit',
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->assertValidationError('account_number');
    }

    #[Test]
    public function testCannotExecuteDepositTwiceWithSameToken(): void
    {
        $this->client->jsonRequest('POST', '/api/transactions/init-deposit', [
            'account_number' => $this->account->getAccountNumber(),
            'amount' => 150.00,
            'description' => 'Initial deposit',
        ]);

        self::assertResponseStatusCodeSame(201);
        $token = $this->responseJson()['data']['token'];

        $this->client->jsonRequest('POST', '/api/transactions/execute-deposit', [
            'token' => $token,
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->client->jsonRequest('POST', '/api/transactions/execute-deposit', [
            'token' => $token,
        ]);
        self::assertResponseStatusCodeSame(400);
    }

    private function assertTransferExists(
        ?int $senderAccountId = null,
        ?int $receiverAccountId = null,
        string $amount = '0.00',
        TypeTransfer $type = TypeTransfer::TRANSFER,
        StatusTransfer $status = StatusTransfer::PENDING,
        ?string $description = null,
    ): void {
        $queryBuilder = $this->entityManager->getRepository(Transfer::class)
            ->createQueryBuilder('transfer')
            ->andWhere('transfer.amount = :amount')
            ->andWhere('transfer.currency = :currency')
            ->andWhere('transfer.type = :type')
            ->andWhere('transfer.status = :status')
            ->setParameter('amount', $amount)
            ->setParameter('currency', $this->currency)
            ->setParameter('type', $type)
            ->setParameter('status', $status);

        if ($senderAccountId !== null) {
            $queryBuilder
                ->andWhere('IDENTITY(transfer.senderAccount) = :senderAccountId')
                ->setParameter('senderAccountId', $senderAccountId);
        }

        if ($receiverAccountId !== null) {
            $queryBuilder
                ->andWhere('IDENTITY(transfer.receiverAccount) = :receiverAccountId')
                ->setParameter('receiverAccountId', $receiverAccountId);
        }

        // Filtre sur description uniquement si fourni
        if ($description !== null) {
            $queryBuilder
                ->andWhere('transfer.description = :description')
                ->setParameter('description', $description);
        }

        $transfers = $queryBuilder->getQuery()->getResult();

        self::assertNotEmpty($transfers);
    }

    private function assertValidationError(string $field): void
    {
        $body = $this->responseJson();
        $violations = $body['violations'] ?? [];

        self::assertTrue(
            array_reduce(
                $violations,
                static fn (bool $found, array $violation): bool => $found || ($violation['propertyPath'] ?? null) === $field,
                false
            ),
            sprintf('Expected a validation error for "%s".', $field)
        );
    }

    /** @return array<string, mixed> */
    private function responseJson(): array
    {
        return json_decode(
            $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }
}