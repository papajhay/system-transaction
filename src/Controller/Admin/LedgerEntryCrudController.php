<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\LedgerEntry;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;

final class LedgerEntryCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return LedgerEntry::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Ledger entry')
            ->setEntityLabelInPlural('Ledger entries')
            ->setSearchFields([
                'fromCurrency.code',
                'toCurrency.code',
                'transfer.reference',
            ])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('fromCurrency', 'Source')
            ->setFormTypeOption('choice_label', 'code')
            ->setRequired(true);

        yield AssociationField::new('toCurrency', 'Target')
            ->setFormTypeOption('choice_label', 'code')
            ->setRequired(true);

        yield AssociationField::new('transfer', 'Transfer')
            ->setFormTypeOption('choice_label', 'reference')
            ->setRequired(true)
            ->hideOnIndex();

        yield NumberField::new('exchangeRate', 'Exchange rate')
            ->setNumDecimals(6)
            ->setStoredAsString(true)
            ->setRequired(true)
            ->formatValue(fn ($value, LedgerEntry $ledgerEntry): string => $this->formatExchangeRate($value, $ledgerEntry));

        yield NumberField::new('sourceAmount', 'Source amount')
            ->setNumDecimals(2)
            ->setStoredAsString(true)
            ->setRequired(true)
            ->formatValue(fn ($value, LedgerEntry $ledgerEntry): string => $this->formatAmount($value, $ledgerEntry->getFromCurrency()?->getSymbol()));

        yield NumberField::new('targetAmount', 'Target amount')
            ->setNumDecimals(2)
            ->setStoredAsString(true)
            ->setRequired(true)
            ->formatValue(fn ($value, LedgerEntry $ledgerEntry): string => $this->formatAmount($value, $ledgerEntry->getToCurrency()?->getSymbol()));

        yield DateTimeField::new('createdAt', 'Created at')
            ->hideOnForm()
            ->hideOnIndex();

        yield DateTimeField::new('updatedAt', 'Updated at')
            ->hideOnForm()
            ->hideOnIndex();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions;
    }

    public function createEntity(string $entityFqcn): LedgerEntry
    {
        $now = new DateTimeImmutable();

        return (new LedgerEntry())
            ->setExchangeRate('0.000000')
            ->setSourceAmount('0.00')
            ->setTargetAmount('0.00')
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

    private function formatExchangeRate($value, LedgerEntry $ledgerEntry): string
    {
        $decimals = $ledgerEntry->getFromCurrency()?->getCode() === 'MGA' ? 6 : 2;

        return $this->formatAmount($value, $ledgerEntry->getToCurrency()?->getSymbol(), $decimals);
    }

    private function formatAmount($value, ?string $symbol, int $decimals = 2): string
    {
        return number_format((float) $value, $decimals, ',', ' ') . ($symbol === null ? '' : ' ' . $symbol);
    }
}
