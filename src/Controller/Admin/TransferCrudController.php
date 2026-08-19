<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Transfer;
use App\Enum\StatusTransfer;
use App\Enum\TypeTransfer;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class TransferCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Transfer::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Transfer')
            ->setEntityLabelInPlural('Transfers')
            ->setSearchFields(['token', 'reference', 'type', 'status']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('reference', 'Reference')
            ->setRequired(true);

        yield TextField::new('token', 'Token')
            ->setRequired(true);

        yield AssociationField::new('senderAccount', 'Sender')
            ->setFormTypeOption('choice_label', 'accountNumber')
            ->setRequired(false);

        yield AssociationField::new('receiverAccount', 'Receiver')
            ->setFormTypeOption('choice_label', 'accountNumber')
            ->setRequired(false);

        yield NumberField::new('amount', 'Amount')
            ->setNumDecimals(2)
            ->setStoredAsString(true)
            ->setRequired(true);

        yield AssociationField::new('currency', 'Currency')
            ->setRequired(true);

        yield ChoiceField::new('type', 'Type')
            ->setChoices([
                'Deposit' => TypeTransfer::DEPOSIT,
                'Withdrawal' => TypeTransfer::WITHDRAWAL,
                'Transfer' => TypeTransfer::TRANSFER,
            ])
            ->setRequired(true)
            ->renderAsBadges([
                TypeTransfer::DEPOSIT->value => 'success',
                TypeTransfer::WITHDRAWAL->value => 'danger',
                TypeTransfer::TRANSFER->value => 'primary',
            ]);

        yield ChoiceField::new('status', 'Status')
            ->setChoices([
                'Pending' => StatusTransfer::PENDING,
                'Completed' => StatusTransfer::COMPLETED,
                'Failed' => StatusTransfer::FAILED,
            ])
            ->setRequired(true)
            ->renderAsBadges([
                StatusTransfer::PENDING->value => 'warning',
                StatusTransfer::COMPLETED->value => 'success',
                StatusTransfer::FAILED->value => 'danger',
            ]);

        yield TextareaField::new('description', 'Description')
            ->setNumOfRows(3)
            ->setMaxLength(255)
            ->hideOnIndex();

        yield DateTimeField::new('processedAt', 'Processed at')
            ->hideOnForm();

        yield DateTimeField::new('expiresAt', 'Expires at')
            ->hideOnForm();

        yield DateTimeField::new('createdAt', 'Created at')
            ->hideOnForm();

        yield DateTimeField::new('updatedAt', 'Updated at')
            ->hideOnForm();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }
}
