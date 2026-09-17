<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Fee;
use App\Controller\Admin\BaseCrudController;
use App\Enum\TypeFee;
use DateTimeImmutable;
use DateTimeInterface;
use App\Service\DateRangeFilter;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Factory\FilterFactory;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FeeCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return Fee::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Fee')
            ->setEntityLabelInPlural('Fees')
            ->setSearchFields(['type', 'transfer.reference'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(
                ChoiceFilter::new('type', 'Type')
                    ->setChoices([
                    'Fixed fee' => TypeFee::FEE_CHARGED_FIXED,
                    'Rate fee' => TypeFee::FEE_CHARGED_RATE,
                    'Free charged' => TypeFee::FREE_CHARGED,
                    ])
            )
            ->add(EntityFilter::new('transfer', 'Transfer'))
            ->add(DateRangeFilter::new('createdAt', 'Created at'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('transfer', 'Transfer')
            ->setFormTypeOption('choice_label', 'reference')
            ->setRequired(false);

        yield ChoiceField::new('type', 'Type')
            ->setChoices([
                'Fixed fee' => TypeFee::FEE_CHARGED_FIXED,
                'Rate fee' => TypeFee::FEE_CHARGED_RATE,
                'Free charged' => TypeFee::FREE_CHARGED,
            ])
            ->setRequired(true);

        yield TextField::new('name', 'Name')
            ->setRequired(false);

        yield NumberField::new('rate', 'Rate (%)')
            ->setNumDecimals(4)
            ->setRequired(false);

        yield NumberField::new('amount', 'Amount')
            ->setNumDecimals(2)
            ->setRequired(true);

        yield DateTimeField::new('createdAt', 'Created at')
            ->hideOnForm();

        yield DateTimeField::new('updatedAt', 'Updated at')
            ->hideOnForm();
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
            throw new \LogicException('The CSV export can only be used from the fee index page.');
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
                'type',
                'name',
                'rate',
                'amount',
                'created_at',
                'updated_at',
            ]);

            /** @var Fee $fee */
            foreach ($queryBuilder->getQuery()->toIterable() as $fee) {
                fputcsv($output, [
                    $fee->getId(),
                    $fee->getTransfer()?->getReference() ?? '',
                    $fee->getType()->value,
                    $fee->getName() ?? '',
                    $fee->getRate(),
                    $fee->getAmount(),
                    $fee->getCreatedAt()?->format(DateTimeInterface::ATOM) ?? '',
                    $fee->getUpdatedAt()?->format(DateTimeInterface::ATOM) ?? '',
                ]);
            }

            fclose($output);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="fees.csv"');

        return $response;
    }

    public function createEntity(string $entityFqcn): Fee
    {
        $now = new DateTimeImmutable();

        return (new Fee())
            ->setType(TypeFee::FREE_CHARGED)
            ->setAmount(0.0)
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
}