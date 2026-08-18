<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Currency;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Length;

final class CurrencyCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Currency::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Currency')
            ->setEntityLabelInPlural('Currencies')
            ->setSearchFields(['code', 'name', 'symbol']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('code', 'Code')
            ->setRequired(true)
            ->setFormTypeOption('attr', [
                'maxlength' => 3,
                'style' => 'text-transform: uppercase',
            ])
            ->setFormTypeOption('constraints', [
                new NotBlank(),
                new Length(min: 3, max: 3),
            ]);
        yield TextField::new('name', 'Name');
        yield TextField::new('symbol', 'Symbol');
        yield AssociationField::new('accounts', 'Accounts')
            ->hideOnIndex()
            ->hideOnForm();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions;
    }
}
