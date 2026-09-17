<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Conversion;
use App\Controller\Admin\BaseCrudController;
use App\Enum\StatusTransfer;
use App\Service\DateRangeFilter;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Factory\FilterFactory;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ConversionCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return Conversion::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Conversion')
            ->setEntityLabelInPlural('Conversions')
            ->setSearchFields([
                'fromCurrency.code',
                'toCurrency.code',
                'transfer.reference',
            ])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('toCurrency', 'Target'))
            ->add(EntityFilter::new('fromCurrency', 'Source'))
            ->add(DateRangeFilter::new('createdAt', 'Created at'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('fromCurrency', 'Source')
            ->setFormTypeOption('choice_label', 'code')
            ->setRequired(true);

        yield AssociationField::new('toCurrency', 'Target')
            ->setFormTypeOption('choice_label', 'code')
            ->setRequired(true);

        yield AssociationField::new('transfer', 'Transfer')
            ->setFormTypeOption('choice_label', 'reference')
            ->setRequired(true)
            ->hideOnIndex();

        yield ChoiceField::new('transfer.status', 'Status')
            ->setChoices([
                'Pending' => StatusTransfer::PENDING,
                'Completed' => StatusTransfer::COMPLETED,
                'Failed' => StatusTransfer::FAILED,
            ])
            ->renderAsBadges(StatusTransfer::statusBadgeStyles())
            ->hideOnForm();

        yield NumberField::new('exchangeRate', 'Exchange rate')
            ->setNumDecimals(6)
            ->setStoredAsString(true)
            ->setRequired(true)
            ->formatValue(fn ($value, Conversion $conversion): string => $this->formatExchangeRate($value, $conversion));

        yield NumberField::new('sourceAmount', 'Source amount')
            ->setNumDecimals(2)
            ->setStoredAsString(true)
            ->setRequired(true)
            ->formatValue(fn ($value, Conversion $conversion): string => $this->formatAmount($value, $conversion->getFromCurrency()?->getSymbol()));

        yield NumberField::new('targetAmount', 'Target amount')
            ->setNumDecimals(2)
            ->setStoredAsString(true)
            ->setRequired(true)
            ->formatValue(fn ($value, Conversion $conversion): string => $this->formatAmount($value, $conversion->getToCurrency()?->getSymbol()));

        yield DateTimeField::new('createdAt', 'Created at')
            ->hideOnIndex();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->configureCommonActions(
            $actions->add(
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
            throw new \LogicException('The CSV export can only be used from the conversion index page.');
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
                'transfer',
                'from_currency',
                'to_currency',
                'exchange_rate',
                'source_amount',
                'target_amount',
                'created_at',
                'updated_at',
            ]);

            /** @var Conversion $conversion */
            foreach ($queryBuilder->getQuery()->toIterable() as $conversion) {
                fputcsv($output, [
                    $conversion->getId(),
                    $conversion->getTransfer()?->getReference() ?? '',
                    $conversion->getFromCurrency()?->getCode() ?? '',
                    $conversion->getToCurrency()?->getCode() ?? '',
                    $conversion->getExchangeRate(),
                    $conversion->getSourceAmount(),
                    $conversion->getTargetAmount(),
                    $conversion->getCreatedAt()?->format(DateTimeInterface::ATOM) ?? '',
                    $conversion->getUpdatedAt()?->format(DateTimeInterface::ATOM) ?? '',
                ]);
            }

            fclose($output);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="conversions.csv"');

        return $response;
    }

    public function createEntity(string $entityFqcn): Conversion
    {
        $now = new DateTimeImmutable();

        return (new Conversion())
            ->setExchangeRate('0.000000')
            ->setSourceAmount('0.00')
            ->setTargetAmount('0.00')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $entityManager->persist($entityInstance);
        $entityManager->flush();
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $entityInstance->setUpdatedAt(new DateTimeImmutable());
        $entityManager->flush();
    }

    private function formatExchangeRate($value, Conversion $conversion): string
    {
        $decimals = $conversion->getFromCurrency()?->getCode() === 'MGA' ? 6 : 2;

        return $this->formatAmount($value, $conversion->getToCurrency()?->getSymbol(), $decimals);
    }

    private function formatAmount($value, ?string $symbol, int $decimals = 2): string
    {
        return number_format((float) $value, $decimals, ',', ' ') . ($symbol === null ? '' : ' ' . $symbol);
    }
}