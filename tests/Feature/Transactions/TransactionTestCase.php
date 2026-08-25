<?php

declare(strict_types=1);

namespace App\Tests\Feature\Transactions;

use App\Entity\Account;
use App\Entity\Currency;
use App\Entity\User;
use App\Enum\Role;
use App\Enum\StatusAccount;
use App\Enum\TypeAccount;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class TransactionTestCase extends WebTestCase
{
    protected EntityManagerInterface $entityManager;

    protected KernelBrowser $client;

    protected User $user;

    protected Currency $currency;

    protected Account $account;

    protected Account $systemAccount;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The transaction tests require the pdo_sqlite extension.');
        }

        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        // RefreshDatabase equivalent: rebuild the test schema for every test.
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new User())
            ->setName('Test User')
            ->setEmail('test@example.com')
            ->setRole(Role::USER);
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));

        $this->currency = (new Currency())
            ->setCode('USD')
            ->setName('US Dollar')
            ->setSymbol('$');

        $now = new DateTimeImmutable();

        $this->account = (new Account())
            ->setAccountNumber('ACC-123-456')
            ->setBalance('150.00')
            ->setCurrency($this->currency)
            ->setStatus(StatusAccount::ACTIVE)
            ->setType(TypeAccount::USER)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        $this->systemAccount = (new Account())
            ->setAccountNumber('SYS-001')
            ->setBalance('0.15')
            ->setCurrency($this->currency)
            ->setStatus(StatusAccount::ACTIVE)
            ->setType(TypeAccount::SYSTEM)
            ->setSystemName('withdrawal-fee')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        $this->entityManager->persist($this->user);
        $this->entityManager->persist($this->currency);
        $this->entityManager->persist($this->account);
        $this->entityManager->persist($this->systemAccount);
        $this->entityManager->flush();

        // Symfony Security equivalent of Sanctum::actingAs($this->user, ['*']).
        // loginUser() covers the test firewall; the JWT is also installed because
        // the API firewall is stateless and does not use the session.
        $this->client->loginUser($this->user);
        $this->client->jsonRequest('POST', '/api/login_check', [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        self::assertResponseIsSuccessful();
        $response = json_decode(
            $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$response['token']);
    }
}
