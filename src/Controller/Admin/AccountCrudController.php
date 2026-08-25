<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Account;
use App\Enum\StatusAccount;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use App\Enum\TypeAccount;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Uid\Uuid;

class AccountCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Account::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Account')
            ->setEntityLabelInPlural('Accounts')
             ->setSearchFields([
                'accountNumber',
                'systemName',
                'status',
                'type',
            ]);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('accountNumber', 'Number')
            ->setRequired(true)
            ->setFormTypeOption('disabled', true);

        yield NumberField::new('balance', 'Balance')
            ->setNumDecimals(2)
            ->setStoredAsString(true)
            ->setRequired(true)
            ->setFormTypeOption('disabled', true);

        yield ChoiceField::new('type', 'Type')
            ->setChoices([
                'User' => TypeAccount::USER,
                'System' => TypeAccount::SYSTEM,
            ])
            ->setRequired(true);

        yield TextField::new('systemName', 'System name')
            ->setRequired(false);

        yield AssociationField::new('currency', 'Currency')
            ->setRequired(true);

        yield ChoiceField::new('status', 'Status')
            ->setChoices([
                'Active' => StatusAccount::ACTIVE,
                'Suspended' => StatusAccount::SUSPENDED,
                'Closed' => StatusAccount::CLOSED,
            ])
            ->setRequired(true)
            ->renderAsBadges(StatusAccount::badgeStyles());
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions;
    }

    public function createEntity(string $entityFqcn): Account
    {
        $now = new DateTimeImmutable();

        return (new Account())
            ->setAccountNumber(Uuid::v4()->toRfc4122())
            ->setBalance('0.00')
            ->setStatus(StatusAccount::ACTIVE)
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