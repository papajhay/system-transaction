<?php

declare(strict_types=1);

namespace App\Tests\Feature\ExchangeRates;

use App\Entity\Currency;
use App\Entity\ExchangeRate;
use App\Service\ExchangeRateProvider;
use App\Service\OnDemandExchangeRateService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class OnDemandExchangeRateServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The on-demand exchange-rate test requires the pdo_sqlite extension.');
        }

        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testItCreatesTodayThenUpdatesOnlyToday(): void
    {
        [$baseCurrency, $targetCurrency] = $this->createCurrencies();
        $yesterday = new \DateTimeImmutable('2026-09-15 12:00:00');
        $historicalRate = (new ExchangeRate())
            ->setBaseCurrency($baseCurrency)
            ->setTargetCurrency($targetCurrency)
            ->setRate('0.91')
            ->setRateDate($yesterday)
            ->setCreatedAt($yesterday)
            ->setUpdatedAt($yesterday);
        $this->entityManager->persist($historicalRate);
        $this->entityManager->flush();
        $historicalRateId = $historicalRate->getId();

        $service = $this->createService('0.93');
        $todayRate = $service->getRate($baseCurrency, $targetCurrency);

        self::assertNotNull($todayRate);
        self::assertSame('0.93', $todayRate->getRate());
        self::assertSame((new \DateTimeImmutable())->format('Y-m-d'), $todayRate->getRateDate()->format('Y-m-d'));
        self::assertSame($todayRate->getCreatedAt()->format('Y-m-d'), $todayRate->getUpdatedAt()->format('Y-m-d'));

        $createdAt = $todayRate->getCreatedAt();
        $refreshedRate = $this->createService('0.94')->getRate($baseCurrency, $targetCurrency);

        self::assertNotNull($refreshedRate);
        self::assertSame('0.94', $refreshedRate->getRate());
        self::assertEquals($createdAt, $refreshedRate->getCreatedAt());
        self::assertGreaterThanOrEqual($createdAt, $refreshedRate->getUpdatedAt());

        $this->entityManager->clear();
        $persistedHistoricalRate = $this->entityManager->getRepository(ExchangeRate::class)->find($historicalRateId);
        self::assertNotNull($persistedHistoricalRate);
        self::assertSame('0.91', $persistedHistoricalRate->getRate());
        self::assertSame('2026-09-15', $persistedHistoricalRate->getRateDate()->format('Y-m-d'));
        self::assertSame('2026-09-15', $persistedHistoricalRate->getCreatedAt()->format('Y-m-d'));
        self::assertSame('2026-09-15', $persistedHistoricalRate->getUpdatedAt()->format('Y-m-d'));
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

    private function createService(string $rate): OnDemandExchangeRateService
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'result' => 'success',
            'conversion_rates' => ['USD' => $rate],
        ]);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        return new OnDemandExchangeRateService(
            $this->entityManager,
            new ExchangeRateProvider($httpClient, 'https://example.test/{base}/{api_key}', 'test-key'),
            $this->entityManager->getRepository(ExchangeRate::class),
        );
    }
}
