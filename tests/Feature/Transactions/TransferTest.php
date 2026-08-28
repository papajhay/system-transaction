<?php

declare(strict_types=1);

namespace App\Tests\Feature\Transactions;

use App\Entity\Account;
use App\Entity\Transfer;
use App\Enum\StatusAccount;
use App\Enum\StatusTransfer;
use App\Enum\TypeAccount;
use App\Enum\TypeTransfer;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;

final class TransferTest extends TransactionTestCase
{
    #[Test]
    public function authenticatedUserCanInitializeAMonoTransfer(): void
    {
        $receiver = $this->account('ACC-654-321');
        $this->initMono($receiver, 50, 'Wallet transfer');
        self::assertResponseStatusCodeSame(201);
        $body = $this->jsonResponse(); self::assertArrayHasKey('status_code', $body); self::assertArrayHasKey('message', $body);
        self::assertArrayHasKey('token', $body['data']); self::assertArrayHasKey('reference', $body['data']);
        $this->assertTransfer($receiver, '50.000000', StatusTransfer::PENDING, 'Wallet transfer');
    }

    #[Test]
    public function authenticatedUserCanExecuteAMonoTransferWithToken(): void
    {
        $receiver = $this->account('ACC-654-321'); $token = $this->initMono($receiver, 50, 'Wallet transfer');
        $this->execute('/api/transactions/execute-transfer', ['token' => $token]); self::assertResponseStatusCodeSame(201);
        $body = $this->jsonResponse(); self::assertSame(201, $body['status_code']); self::assertSame('Transfer executed successfully', $body['message']);
        self::assertSame($token, $body['data']['token']); self::assertSame('completed', $body['data']['status']);
        $this->assertTransfer($receiver, '50.000000', StatusTransfer::COMPLETED);
        $this->assertBalances('95.000000', '50.000000', '5.150000');
    }

    #[Test]
    public function authenticatedUserCanInitializeAMultiTransfer(): void
    {
        $one = $this->account('ACC-111-111'); $two = $this->account('ACC-222-222');
        $this->client->jsonRequest('POST', '/api/transactions/init-multi-transfer', ['transfers' => [$this->item($one, 30, 'First payment'), $this->item($two, 20, 'Second payment')]]);
        self::assertResponseStatusCodeSame(201); $body = $this->jsonResponse(); self::assertArrayHasKey('status_code', $body); self::assertCount(2, $body['data']);
        $this->assertTransfer($one, '30.000000', StatusTransfer::PENDING, 'First payment'); $this->assertTransfer($two, '20.000000', StatusTransfer::PENDING, 'Second payment');
    }

    #[Test]
    public function authenticatedUserCanExecuteAMultiTransferWithTokens(): void
    {
        $one = $this->account('ACC-111-111'); $two = $this->account('ACC-222-222');
        $this->client->jsonRequest('POST', '/api/transactions/init-multi-transfer', ['transfers' => [$this->item($one, 30, 'First payment'), $this->item($two, 20, 'Second payment')]]);
        self::assertResponseStatusCodeSame(201); $tokens = array_column($this->jsonResponse()['data'], 'token');
        $this->execute('/api/transactions/execute-multi-transfer', ['tokens' => $tokens]); self::assertResponseStatusCodeSame(201);
        $body = $this->jsonResponse(); self::assertSame(201, $body['status_code']); self::assertSame('Multi transfer executed successfully', $body['message']); self::assertCount(2, $body['data']);
        self::assertSame('completed', $body['data'][0]['status']); self::assertSame('completed', $body['data'][1]['status']);
        $this->assertTransferToken($tokens[0], '30.000000'); $this->assertTransferToken($tokens[1], '20.000000'); $this->assertBalances('95.000000', '30.000000', '5.150000');
    }

    #[Test]
    public function executeMultiTransferWithInvalidTokensReturnsError(): void
    {
        $this->execute('/api/transactions/execute-multi-transfer', ['tokens' => ['invalid-token-1', 'invalid-token-2']]); self::assertResponseStatusCodeSame(422);
        $this->assertValidationError('tokens[0]'); $this->assertValidationError('tokens[1]');
    }

    #[Test]
    public function guestCannotInitializeAMonoTransfer(): void
    {
        $this->client->setServerParameter('HTTP_AUTHORIZATION', ''); $this->client->jsonRequest('POST', '/api/transactions/init-transfer', ['from_account_number' => $this->account->getAccountNumber(), 'to_account_number' => 'ACC-654-321', 'amount' => 50, 'description' => 'Wallet transfer']); self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function cannotExecuteMonoTransferTwiceWithSameToken(): void
    {
        $receiver = $this->account('ACC-654-321'); $token = $this->initMono($receiver, 50, 'Wallet transfer'); $this->execute('/api/transactions/execute-transfer', ['token' => $token]); self::assertResponseStatusCodeSame(201); $this->execute('/api/transactions/execute-transfer', ['token' => $token]); self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function transferAmountMustBePositive(): void
    {
        foreach ([0, -10.00] as $amount) { $this->client->jsonRequest('POST', '/api/transactions/init-transfer', ['from_account_number' => $this->account->getAccountNumber(), 'to_account_number' => 'ACC-654-321', 'amount' => $amount, 'description' => 'Invalid transfer']); self::assertResponseStatusCodeSame(422); $this->assertValidationError('amount'); }
    }

    #[Test]
    public function senderAccountMustExist(): void
    {
        $this->client->jsonRequest('POST', '/api/transactions/init-transfer', ['from_account_number' => 'NON-EXISTENT-FROM', 'to_account_number' => 'ACC-654-321', 'amount' => 10, 'description' => 'Transfer']); self::assertResponseStatusCodeSame(422); $this->assertValidationError('from_account_number');
    }

    #[Test]
    public function receiverAccountMustExist(): void
    {
        $this->client->jsonRequest('POST', '/api/transactions/init-transfer', ['from_account_number' => $this->account->getAccountNumber(), 'to_account_number' => 'NON-EXISTENT-TO', 'amount' => 10, 'description' => 'Transfer']); self::assertResponseStatusCodeSame(422); $this->assertValidationError('to_account_number');
    }

    #[Test]
    public function executeTransferWithInsufficientBalance(): void
    {
        $receiver = $this->account('ACC-999-000'); $this->client->jsonRequest('POST', '/api/transactions/init-transfer', ['from_account_number' => $this->account->getAccountNumber(), 'to_account_number' => $receiver->getAccountNumber(), 'amount' => 100000, 'description' => 'Large transfer']); self::assertResponseStatusCodeSame(400);
        self::assertNull($this->entityManager->getRepository(Transfer::class)->findOneBy(['senderAccount' => $this->account, 'amount' => '100000.000000']));
    }

    #[Test]
    public function sameAccountTransferChargesFee(): void
    {
        $token = $this->initMono($this->account, 10, 'Self transfer'); $this->execute('/api/transactions/execute-transfer', ['token' => $token]); self::assertResponseStatusCodeSame(201); self::assertSame('completed', $this->jsonResponse()['data']['status']);
        $this->entityManager->clear(); $account = $this->entityManager->find(Account::class, $this->account->getId()); $system = $this->entityManager->find(Account::class, $this->systemAccount->getId()); self::assertSame('149.00', number_format((float) $account?->getBalance(), 2, '.', '')); self::assertSame('1.15', number_format((float) $system?->getBalance(), 2, '.', ''));
    }

    private function account(string $number): Account { $now = new DateTimeImmutable(); $account = (new Account())->setAccountNumber($number)->setBalance('0.00')->setCurrency($this->currency)->setStatus(StatusAccount::ACTIVE)->setType(TypeAccount::USER)->setCreatedAt($now)->setUpdatedAt($now); $this->entityManager->persist($account); $this->entityManager->flush(); return $account; }
    private function item(Account $to, float $amount, string $description): array { return ['from_account_number' => $this->account->getAccountNumber(), 'to_account_number' => $to->getAccountNumber(), 'amount' => $amount, 'description' => $description]; }
    private function initMono(Account $to, float $amount, string $description): string { $this->client->jsonRequest('POST', '/api/transactions/init-transfer', $this->item($to, $amount, $description)); self::assertResponseStatusCodeSame(201); return $this->jsonResponse()['data']['token']; }
    private function execute(string $path, array $payload): void { $this->client->jsonRequest('POST', $path, $payload); }
    private function assertTransfer(Account $receiver, string $amount, StatusTransfer $status, ?string $description = null): void { $query = $this->entityManager->getRepository(Transfer::class)->findOneBy(['senderAccount' => $this->account, 'receiverAccount' => $receiver, 'currency' => $this->currency, 'type' => TypeTransfer::TRANSFER, 'status' => $status]); self::assertNotNull($query); self::assertSame($amount, number_format((float) $query->getAmount(), 6, '.', '')); if ($description !== null) self::assertSame($description, $query->getDescription()); }
    private function assertTransferToken(string $token, string $amount): void { $transfer = $this->entityManager->getRepository(Transfer::class)->findOneBy(['token' => $token]); self::assertNotNull($transfer); self::assertSame($amount, number_format((float) $transfer->getAmount(), 6, '.', '')); self::assertSame(StatusTransfer::COMPLETED, $transfer->getStatus()); }
    private function assertBalances(string $sender, string $receiver, string $system): void { $this->entityManager->clear(); $account = $this->entityManager->find(Account::class, $this->account->getId()); $number = $receiver === '50.000000' ? 'ACC-654-321' : ($receiver === '30.000000' ? 'ACC-111-111' : 'ACC-222-222'); $receiverAccount = $this->entityManager->getRepository(Account::class)->findOneBy(['accountNumber' => $number]); $systemAccount = $this->entityManager->find(Account::class, $this->systemAccount->getId()); self::assertSame(number_format((float) $sender, 2, '.', ''), number_format((float) $account?->getBalance(), 2, '.', '')); self::assertSame(number_format((float) $receiver, 2, '.', ''), number_format((float) $receiverAccount?->getBalance(), 2, '.', '')); self::assertSame(number_format((float) $system, 2, '.', ''), number_format((float) $systemAccount?->getBalance(), 2, '.', '')); }
    private function assertValidationError(string $field): void { $body = $this->jsonResponse(); self::assertTrue(array_reduce($body['violations'] ?? [], static fn(bool $found, array $v): bool => $found || ($v['propertyPath'] ?? null) === $field, false), 'Expected validation error for '.$field); }
    /** @return array<string,mixed> */ private function jsonResponse(): array { return json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR); }
}
