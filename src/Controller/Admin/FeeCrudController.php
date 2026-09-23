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
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Factory\FilterFactory;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
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
            ->setSearchFields(['transfer.reference'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('transfer', 'Transfer'))
            ->add(NumericFilter::new('amount', 'Amount'))
            ->add(NumericFilter::new('rate', 'Rate'))
            ->add(DateRangeFilter::new('createdAt', 'Created at'));
    }

    public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters,
    ): QueryBuilder {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->andWhere('entity.type != :freeCharged')
            ->setParameter('freeCharged', TypeFee::FREE_CHARGED->value);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('transfer', 'Transfer')
            ->setFormTypeOption('choice_label', 'reference')
            ->setRequired(false);

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
        return $actions
            ->disable(Action::DELETE, Action::BATCH_DELETE)
            ->add(
                Crud::PAGE_INDEX,
                    Action::new('view_transfer', 'View Transfer', 'fa fa-eye')
                        ->linkToCrudAction('viewTransfer')
                        ->displayIf(static fn (Fee $fee): bool => null !== $fee->getTransfer())
            )
            ->add(
                Crud::PAGE_INDEX,
                Action::new('export', 'CSV Export', 'fa fa-file-csv')
                    ->createAsGlobalAction()
                    ->linkToCrudAction('export')
            );
    }
    
    public function viewTransfer(AdminContext $context): RedirectResponse
    {
        /** @var Fee $fee */
        $fee = $context->getEntity()->getInstance();
        $transfer = $fee->getTransfer();

        if (null === $transfer || null === $transfer->getId()) {
            throw new \LogicException('This fee is not associated with a transfer.');
        }

        $url = $this->container->get(AdminUrlGenerator::class)
          ->setController(TransferCrudController::class)
          ->setAction(Crud::PAGE_DETAIL)
          ->setEntityId($transfer->getId())
          ->generateUrl();

        return $this->redirect($url);
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
