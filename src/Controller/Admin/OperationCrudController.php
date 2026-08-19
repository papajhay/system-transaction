<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Operation;
use App\Enum\TypeOperation;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;

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
            ->add(NumericFilter::new('amount', 'Amount'))
            ->add(NumericFilter::new('balanceBefore', 'Balance before'))
            ->add(NumericFilter::new('balanceAfter', 'Balance after'))
            ->add(DateTimeFilter::new('createdAt', 'Created at'));
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
            ->renderAsBadges([
                TypeOperation::DEBIT->value => 'danger',
                TypeOperation::CREDIT->value => 'success',
            ])
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
        return $actions->disable(Action::NEW, Action::EDIT);
    }
}
