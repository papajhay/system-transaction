<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Fee;
use App\Enum\TypeFee;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
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

final class FeeCrudController extends AbstractCrudController
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
                        'Fee charged' => TypeFee::FEE_CHARGED,
                        'Free charged' => TypeFee::FREE_CHARGED,
                    ])
            )
            ->add(EntityFilter::new('transfer', 'Transfer'))
            ->add(NumericFilter::new('amount', 'Amount'))
            ->add(DateTimeFilter::new('createdAt', 'Created at'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('transfer', 'Transfer')
            ->setFormTypeOption('choice_label', 'reference')
            ->setRequired(true);

        yield ChoiceField::new('type', 'Type')
            ->setChoices([
                'Fee charged' => TypeFee::FEE_CHARGED,
                'Free charged' => TypeFee::FREE_CHARGED,
            ])
            ->setRequired(true);

        yield NumberField::new('amount', 'Amount')
            ->setNumDecimals(2)
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

    public function createEntity(string $entityFqcn): Fee
    {
        $now = new DateTimeImmutable();

        return (new Fee())
            ->setType(TypeFee::FREE_CHARGED)
            ->setAmount('0.00')
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
