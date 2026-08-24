<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\Role;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ApiAuthenticationTest extends WebTestCase
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

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        foreach ([
            ['api@example.com', Role::API_USER],
            ['user@example.com', Role::USER],
            ['admin@example.com', Role::ADMIN],
        ] as [$email, $role]) {
            $user = (new User())
                ->setName(ucfirst((string) $role->name))
                ->setEmail($email)
                ->setRole($role);
            $user->setPassword($hasher->hashPassword($user, 'password'));
            $this->entityManager->persist($user);
        }
        $this->entityManager->flush();
    }

    public function testValidLoginReturnsJwt(): void
    {
        $this->client->jsonRequest('POST', '/api/login_check', [
            'email' => 'api@example.com',
            'password' => 'password',
        ]);

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('token', $this->responseJson());
    }

    public function testInvalidLoginReturnsUnauthorized(): void
    {
        $this->client->jsonRequest('POST', '/api/login_check', [
            'email' => 'api@example.com',
            'password' => 'incorrect',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testApiRequiresJwtAndAcceptsValidJwt(): void
    {
        $this->client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(401);

        $token = $this->loginAs('api@example.com');
        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);

        self::assertResponseIsSuccessful();
        $body = $this->responseJson();
        self::assertSame('api@example.com', $body['email']);
        self::assertArrayNotHasKey('password', $body);
    }

    public function testRoleBasedApiAccess(): void
    {
        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->loginAs('user@example.com'),
        ]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('POST', '/api/currencies', [], server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->loginAs('user@example.com'),
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->loginAs('admin@example.com'),
        ]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('POST', '/api/currencies', [], server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->loginAs('admin@example.com'),
        ]);
        self::assertNotSame(403, $this->client->getResponse()->getStatusCode());
    }

    private function loginAs(string $email): string
    {
        $this->client->jsonRequest('POST', '/api/login_check', [
            'email' => $email,
            'password' => 'password',
        ]);

        self::assertResponseIsSuccessful();

        return $this->responseJson()['token'];
    }

    /** @return array<string, mixed> */
    private function responseJson(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
