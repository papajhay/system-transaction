<?php

declare(strict_types=1);

namespace App\Tests\Feature\ExchangeRates;

use App\Entity\Currency;
use App\Entity\ExchangeRate;
use App\Service\DailyExchangeRateInitializer;
use App\Service\ExchangeRateProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class DailyExchangeRateInitializerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The daily exchange-rate test requires the pdo_sqlite extension.');
        }

        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testItCreatesTodayOnlyOnceAndKeepsPreviousRates(): void
    {
        [$baseCurrency, $targetCurrency] = $this->createCurrencies();
        $previousDate = new \DateTimeImmutable('2026-09-15 12:00:00');
        $previousRate = (new ExchangeRate())
            ->setBaseCurrency($baseCurrency)
            ->setTargetCurrency($targetCurrency)
            ->setRate('0.91')
            ->setCreatedAt($previousDate)
            ->setUpdatedAt($previousDate);
        $this->entityManager->persist($previousRate);
        $this->entityManager->flush();
        $previousRateId = $previousRate->getId();

        $initializer = new DailyExchangeRateInitializer(
            $this->entityManager,
            $this->createProvider(),
            $this->entityManager->getRepository(ExchangeRate::class),
        );

        $initializer->initialize();
        $this->entityManager->clear();
        $ratesAfterFirstRequest = $this->entityManager->getRepository(ExchangeRate::class)->findAll();

        self::assertCount(3, $ratesAfterFirstRequest);
        $persistedPreviousRate = $this->entityManager->getRepository(ExchangeRate::class)->find($previousRateId);
        self::assertNotNull($persistedPreviousRate);
        self::assertSame('2026-09-15', $persistedPreviousRate->getCreatedAt()->format('Y-m-d'));

        $initializer->initialize();
        $this->entityManager->clear();
        $ratesAfterSecondRequest = $this->entityManager->getRepository(ExchangeRate::class)->findAll();

        self::assertCount(3, $ratesAfterSecondRequest);
        self::assertCount(2, array_filter(
            $ratesAfterSecondRequest,
            static fn (ExchangeRate $rate): bool => $rate->getCreatedAt()->format('Y-m-d') === (new \DateTimeImmutable())->format('Y-m-d'),
        ));
    }

    /** @return array{Currency, Currency} */
    private function createCurrencies(): array
    {
        $base = (new Currency())->setCode('EUR')->setName('Euro')->setSymbol('€');
        $target = (new Currency())->setCode('USD')->setName('US Dollar')->setSymbol('$');
        $this->entityManager->persist($base);
        $this->entityManager->persist($target);
        $this->entityManager->flush();

        return [$base, $target];
    }

    private function createProvider(): ExchangeRateProvider
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'result' => 'success',
            'conversion_rates' => ['USD' => '0.93', 'EUR' => '1.07'],
        ]);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        return new ExchangeRateProvider($httpClient, 'https://example.test/{base}/{api_key}', 'test-key');
    }
}
