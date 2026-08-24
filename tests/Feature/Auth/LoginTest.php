<?php

declare(strict_types=1);

namespace App\Tests\Feature\Auth;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LoginTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The functional API tests require the pdo_sqlite extension.');
        }

        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $user = (new User())
            ->setName('Test User')
            ->setEmail('test@example.com');
        $user->setPassword(static::getContainer()
            ->get(UserPasswordHasherInterface::class)
            ->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }

    public function testUserCanLoginAndReceiveJwt(): void
    {
        $this->client->jsonRequest('POST', '/api/login_check', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertArrayHasKey('token', $this->responseJson());
    }

    public function testUserCannotLoginWithWrongPassword(): void
    {
        $this->client->jsonRequest('POST', '/api/login_check', [
            'email' => 'test@example.com',
            'password' => 'password456',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    /** @return array<string, mixed> */
    private function responseJson(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
