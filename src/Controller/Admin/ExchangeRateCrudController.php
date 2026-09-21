<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ExchangeRate;
use App\Repository\ExchangeRateRepository;
use App\Controller\Admin\BaseCrudController;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Factory\FilterFactory;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Validator\Constraints\Positive;

final class ExchangeRateCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return ExchangeRate::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Exchange rate')
            ->setEntityLabelInPlural('Exchange rates')
            ->setSearchFields([
                'baseCurrency.code',
                'targetCurrency.code',
            ])
            ->setDefaultSort([
                'updatedAt' => 'DESC',
            ]);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'ID')
            ->hideOnForm();

        yield AssociationField::new('baseCurrency', 'Base currency')
            ->setFormTypeOption('choice_label', 'code')
            ->setRequired(true);

        yield AssociationField::new('targetCurrency', 'Target currency')
            ->setFormTypeOption('choice_label', 'code')
            ->setRequired(true);

        yield NumberField::new('rate', 'Rate')
            ->setNumDecimals(10)
            ->setStoredAsString(true)
            ->setFormTypeOption('constraints', [new Positive()]);

        yield DateTimeField::new('updatedAt', 'Last updated')
            ->setFormat('MMM d, yyyy HH:mm:ss')
            ->hideOnForm();

        yield DateTimeField::new('createdAt', 'Created at')
            ->setFormat('MMM d, yyyy HH:mm:ss')
            ->hideOnForm();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->configureCommonActions(
            $actions
                ->disable(Action::EDIT)
                ->update(
                    Crud::PAGE_INDEX,
                    Action::NEW,
                    static fn (Action $action) => $action->setLabel('Create')
                )
                ->add(
                    Crud::PAGE_INDEX,
                    Action::new('export', 'CSV Export', 'fa fa-file-csv')
                        ->createAsGlobalAction()
                        ->linkToCrudAction('export')
                )
        );
    }

    public function export(AdminContext $context): StreamedResponse
    {
        $search = $context->getSearch();
        if (null === $search) {
            throw new \LogicException('The CSV export can only be used from the exchange rate index page.');
        }

        $fields = new FieldCollection($this->configureFields(Crud::PAGE_INDEX));
        $filters = $this->container->get(FilterFactory::class)->create(
            $context->getCrud()->getFiltersConfig(),
            $fields,
            $context->getEntity(),
        );
        $queryBuilder = $this->createIndexQueryBuilder(
            $search,
            $context->getEntity(),
            $fields,
            $filters,
        );

        $response = new StreamedResponse(function () use ($queryBuilder): void {
            $output = fopen('php://output', 'wb');
            if (false === $output) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fputcsv($output, [
                'id',
                'base_currency',
                'target_currency',
                'rate',
                'updated_at',
                'created_at',
            ]);

            /** @var ExchangeRate $exchangeRate */
            foreach ($queryBuilder->getQuery()->toIterable() as $exchangeRate) {
                fputcsv($output, [
                    $exchangeRate->getId(),
                    $exchangeRate->getBaseCurrency()?->getCode() ?? '',
                    $exchangeRate->getTargetCurrency()?->getCode() ?? '',
                    $exchangeRate->getRate(),
                    $exchangeRate->getUpdatedAt()->format(DateTimeInterface::ATOM),
                    $exchangeRate->getCreatedAt()->format(DateTimeInterface::ATOM),
                ]);
            }

            fclose($output);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="exchange-rates.csv"');

        return $response;
    }

    public function createEntity(string $entityFqcn): ExchangeRate
    {
        $now = new DateTimeImmutable();

        return (new ExchangeRate())
            ->setRate('0.0000')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
    }

    public function persistEntity(
        EntityManagerInterface $entityManager,
        $entityInstance,
    ): void {
        if (!$entityInstance instanceof ExchangeRate) {
            throw new \InvalidArgumentException('Expected an exchange rate entity.');
        }

        $baseCurrency = $entityInstance->getBaseCurrency();
        $targetCurrency = $entityInstance->getTargetCurrency();
        $rate = (float) $entityInstance->getRate();

        if (null === $baseCurrency || null === $targetCurrency) {
            throw new \InvalidArgumentException('Both currencies are required.');
        }

        if ($baseCurrency === $targetCurrency
            || (null !== $baseCurrency->getId()
                && $baseCurrency->getId() === $targetCurrency->getId())
        ) {
            throw new \InvalidArgumentException('The base and target currencies must be different.');
        }

        if ($rate <= 0) {
            throw new \InvalidArgumentException('The exchange rate must be greater than zero.');
        }

        $entityManager->persist($entityInstance);

        /** @var ExchangeRateRepository $repository */
        $repository = $entityManager->getRepository(ExchangeRate::class);
        $inverse = $repository->findOneByCurrencyPair($targetCurrency, $baseCurrency);

        if (null === $inverse) {
            $now = new DateTimeImmutable();
            $entityManager->persist(
                (new ExchangeRate())
                    ->setBaseCurrency($targetCurrency)
                    ->setTargetCurrency($baseCurrency)
                    ->setRate(number_format(1 / $rate, 10, '.', ''))
                    ->setCreatedAt($now)
                    ->setUpdatedAt($now)
            );
        }

        $entityManager->flush();
    }

    public function updateEntity(
        EntityManagerInterface $entityManager,
        $entityInstance,
    ): void {
        $entityInstance->setUpdatedAt(new DateTimeImmutable());

        $entityManager->flush();
    }
}
