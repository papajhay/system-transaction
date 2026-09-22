<?php

declare(strict_types=1);

namespace App\Tests\Feature\ExchangeRates;

use App\Entity\Currency;
use App\Entity\ExchangeRate;
use App\Controller\Admin\ExchangeRateCrudController;
use App\Service\DateRangeFilter;
use App\Service\ExchangeRateProvider;
use App\Service\UpdateExchangeRatesCommand;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDto;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\ComparisonType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ExchangeRateHistoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The exchange-rate history tests require the pdo_sqlite extension.');
        }

        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testCrudGeneratesAndHidesCreatedAtOnForms(): void
    {
        $controller = new ExchangeRateCrudController();
        $before = new \DateTimeImmutable();
        $exchangeRate = $controller->createEntity(ExchangeRate::class);
        $after = new \DateTimeImmutable();

        self::assertGreaterThanOrEqual($before, $exchangeRate->getCreatedAt());
        self::assertLessThanOrEqual($after, $exchangeRate->getCreatedAt());
        self::assertSame($exchangeRate->getCreatedAt()->format('Y-m-d'), $exchangeRate->getRateDate()->format('Y-m-d'));

        $rateDateField = null;
        foreach ($controller->configureFields(Crud::PAGE_NEW) as $field) {
            if ($field->getAsDto()->getProperty() === 'rateDate') {
                $rateDateField = $field;
                break;
            }
        }

        self::assertNotNull($rateDateField);
        self::assertFalse($rateDateField->getAsDto()->getDisplayedOn()->has(Crud::PAGE_NEW));
    }

    public function testScheduledRunsCreateNewRatesWithTheCurrentDate(): void
    {
        [$baseCurrency, $targetCurrency] = $this->createCurrencies();
        $provider = $this->createProvider('0.93');
        $command = new UpdateExchangeRatesCommand(
            $this->entityManager,
            $provider,
            $this->entityManager->getRepository(ExchangeRate::class),
        );
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([]));
        $firstRunRates = $this->entityManager->getRepository(ExchangeRate::class)->findAll();
        self::assertCount(2, $firstRunRates);
        $firstRateId = $firstRunRates[0]->getId();
        $firstCreatedAt = $firstRunRates[0]->getCreatedAt();

        $secondCommand = new UpdateExchangeRatesCommand(
            $this->entityManager,
            $this->createProvider('0.94'),
            $this->entityManager->getRepository(ExchangeRate::class),
        );
        self::assertSame(0, (new CommandTester($secondCommand))->execute([]));
        $this->entityManager->clear();
        $rates = $this->entityManager->getRepository(ExchangeRate::class)->findAll();

        self::assertCount(2, $rates);
        $updatedRate = $this->entityManager->getRepository(ExchangeRate::class)->find($firstRateId);
        self::assertNotNull($updatedRate);
        self::assertSame('0.94', $updatedRate->getRate());
        self::assertSame((new \DateTimeImmutable())->format('Y-m-d'), $updatedRate->getRateDate()->format('Y-m-d'));
        self::assertSame(
            $firstCreatedAt->format('Y-m-d H:i:s'),
            $updatedRate->getCreatedAt()->format('Y-m-d H:i:s'),
        );
    }

    public function testRateDateFilterReturnsHistoricalRates(): void
    {
        [$baseCurrency, $targetCurrency] = $this->createCurrencies();
        foreach ([
            ['base' => $baseCurrency, 'target' => $targetCurrency, 'date' => '2026-09-01 12:00:00', 'rate' => '0.90'],
            ['base' => $targetCurrency, 'target' => $baseCurrency, 'date' => '2026-09-01 12:00:00', 'rate' => '1.11'],
            ['base' => $baseCurrency, 'target' => $targetCurrency, 'date' => '2026-09-02 12:00:00', 'rate' => '0.91'],
        ] as $data) {
            $this->entityManager->persist(
                (new ExchangeRate())
                    ->setBaseCurrency($data['base'])
                    ->setTargetCurrency($data['target'])
                    ->setRate($data['rate'])
                    ->setRateDate(new \DateTimeImmutable($data['date']))
                    ->setCreatedAt(new \DateTimeImmutable($data['date']))
                    ->setUpdatedAt(new \DateTimeImmutable($data['date'])),
            );
        }
        $this->entityManager->flush();

        $filterDto = new FilterDto();
        $filterDto->setProperty('rateDate');
        $filterData = FilterDataDto::new(0, $filterDto, 'exchangeRate', [
            'comparison' => ComparisonType::EQ,
            'value' => new \DateTimeImmutable('2026-09-01'),
        ]);
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('exchangeRate')
            ->from(ExchangeRate::class, 'exchangeRate');

        $entityDto = new EntityDto(
            ExchangeRate::class,
            $this->entityManager->getClassMetadata(ExchangeRate::class),
        );
        DateRangeFilter::new('rateDate')->apply($queryBuilder, $filterData, null, $entityDto);

        $filteredRates = $queryBuilder->getQuery()->getResult();
        self::assertCount(2, $filteredRates);

        $rateValues = array_map(
            static fn (ExchangeRate $rate): string => $rate->getRate(),
            $filteredRates,
        );
        sort($rateValues);
        self::assertSame(['0.90', '1.11'], $rateValues);
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

    private function createProvider(string $rate): ExchangeRateProvider
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'result' => 'success',
            'conversion_rates' => ['USD' => $rate, 'EUR' => $rate],
        ]);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        return new ExchangeRateProvider($httpClient, 'https://example.test/{base}/{api_key}', 'test-key');
    }
}
