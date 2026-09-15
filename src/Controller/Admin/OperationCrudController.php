<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Operation;
use App\Enum\TypeOperation;
use App\Service\DateRangeFilter;
use DateTimeInterface;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Factory\FilterFactory;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OperationCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Operation::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Operation')
            ->setEntityLabelInPlural('Operations')
            ->setSearchFields([
                'type',
                'account.accountNumber',
                'transfer.reference',
            ])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(
                ChoiceFilter::new('type', 'Type')
                    ->setChoices([
                        'Debit' => TypeOperation::DEBIT,
                        'Credit' => TypeOperation::CREDIT,
                    ])
            )
            ->add(EntityFilter::new('account', 'Account'))
            ->add(EntityFilter::new('transfer', 'Transfer'))
            ->add(DateRangeFilter::new('createdAt', 'Created at'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('account', 'Account')
            ->setFormTypeOption('choice_label', 'accountNumber')
            ->hideOnForm();

        yield AssociationField::new('transfer', 'Transfer')
            ->setFormTypeOption('choice_label', 'reference')
            ->hideOnForm();

        yield ChoiceField::new('type', 'Type')
            ->setChoices([
                'Debit' => TypeOperation::DEBIT,
                'Credit' => TypeOperation::CREDIT,
            ])
            ->renderAsBadges(TypeOperation::getBadgeClass())
            ->setFormTypeOption('disabled', true);

        yield NumberField::new('amount', 'Amount')
            ->setNumDecimals(2)
            ->setStoredAsString(true)
            ->setFormTypeOption('disabled', true);

        yield NumberField::new('balanceBefore', 'Balance before')
            ->setNumDecimals(2)
            ->setStoredAsString(true)
            ->setFormTypeOption('disabled', true);

        yield NumberField::new('balanceAfter', 'Balance after')
            ->setNumDecimals(2)
            ->setStoredAsString(true)
            ->setFormTypeOption('disabled', true);

        yield DateTimeField::new('createdAt', 'Created at')
            ->hideOnForm();

        yield DateTimeField::new('updatedAt', 'Updated at')
            ->hideOnForm();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(
                Crud::PAGE_INDEX,
                Action::new('export', 'CSV Export', 'fa fa-file-csv')
                    ->createAsGlobalAction()
                    ->linkToCrudAction('export')
            );
    }

    public function export(AdminContext $context): StreamedResponse
    {
        $search = $context->getSearch();
        if (null === $search) {
            throw new \LogicException('The CSV export can only be used from the operation index page.');
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
                'account',
                'transfer',
                'type',
                'amount',
                'balance_before',
                'balance_after',
                'created_at',
                'updated_at',
            ]);

            /** @var Operation $operation */
            foreach ($queryBuilder->getQuery()->toIterable() as $operation) {
                fputcsv($output, [
                    $operation->getId(),
                    $operation->getAccount()?->getAccountNumber() ?? '',
                    $operation->getTransfer()?->getReference() ?? '',
                    $operation->getType()->value,
                    $operation->getAmount(),
                    $operation->getBalanceBefore(),
                    $operation->getBalanceAfter(),
                    $operation->getCreatedAt()->format(DateTimeInterface::ATOM),
                    $operation->getUpdatedAt()->format(DateTimeInterface::ATOM),
                ]);
            }

            fclose($output);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="operations.csv"');

        return $response;
    }
}