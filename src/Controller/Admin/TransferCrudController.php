<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Transfer;
use App\Enum\StatusTransfer;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
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

        yield NumberField::new('amount', 'Amount')
        ->setNumDecimals(2)
        ->setStoredAsString(true)
        ->setRequired(true)
        ->formatValue(function ($value, $entity) {
            if ($value === null || $value === '') {
                return '';
            }
            
            $amount = (float) $value;
            
            // $entity est déjà l'objet Transfer, pas besoin de getInstance()
            $currency = $entity->getCurrency();
            $symbol = $currency ? $currency->getSymbol() : 'Ar';
            $formatted = number_format($amount, 2, ',', ' ');
            
            return $formatted . ' ' . $symbol;
        });

    

        yield ChoiceField::new('status', 'Status')
            ->setChoices([
                'Pending' => StatusTransfer::PENDING,
                'Completed' => StatusTransfer::COMPLETED,
                'Failed' => StatusTransfer::FAILED,
            ])
            ->setRequired(true)
            ->renderAsBadges(StatusTransfer::statusBadgeStyles());

        yield TextareaField::new('description', 'Description')
            ->setNumOfRows(3)
            ->setMaxLength(255)
            ->hideOnIndex();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }
}