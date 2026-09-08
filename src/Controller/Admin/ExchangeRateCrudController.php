<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ExchangeRate;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
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

    public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters,
    ): QueryBuilder {
        $startOfDay = new DateTimeImmutable('today');
        $endOfDay = $startOfDay->setTime(23, 59, 59);

        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->andWhere('entity.createdAt BETWEEN :startOfDay AND :endOfDay')
            ->setParameter('startOfDay', $startOfDay)
            ->setParameter('endOfDay', $endOfDay);
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
            ->setFormTypeOption('disabled', true);

        yield DateTimeField::new('createdAt', 'Created at')
            ->setFormat('MMM d, yyyy')
            ->hideOnForm();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT);
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