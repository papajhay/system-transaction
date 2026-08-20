<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ExchangeRate;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;

final class ExchangeRateCrudController extends AbstractCrudController
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
            ->setSearchFields(['baseCurrency.code', 'targetCurrency.code'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('baseCurrency', 'Base currency')
            ->setFormTypeOption('choice_label', 'code')
            ->setRequired(true);

        yield AssociationField::new('targetCurrency', 'Target currency')
            ->setFormTypeOption('choice_label', 'code')
            ->setRequired(true);

        yield NumberField::new('rate', 'Rate')
            ->setNumDecimals(4)
            ->setStoredAsString(true)
            ->setRequired(true);

        yield DateTimeField::new('createdAt', 'Created at')
            ->hideOnForm();

        yield DateTimeField::new('updatedAt', 'Updated at')
            ->hideOnForm();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions;
    }

    public function createEntity(string $entityFqcn): ExchangeRate
    {
        $now = new DateTimeImmutable();

        return (new ExchangeRate())
            ->setRate('0.0000')
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
