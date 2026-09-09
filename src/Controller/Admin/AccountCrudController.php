<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Account;
use App\Enum\StatusAccount;
use App\Enum\TypeAccount;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Uid\Uuid;

final class AccountCrudController extends AbstractCrudController
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

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('accountNumber', 'Account number'));
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
        return $actions
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
            throw new \LogicException('The CSV export can only be used from the account index page.');
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
                'number',
                'balance',
                'type',
                'status',
                'currency',
                'created_at',
                'updated_at',
            ]);

            /** @var Account $account */
            foreach ($queryBuilder->getQuery()->toIterable() as $account) {
                fputcsv($output, [
                    $account->getAccountNumber(),
                    $account->getBalance(),
                    $account->getType()->value,
                    $account->getStatus()->value,
                    $account->getCurrency()?->getCode() ?? '',
                    $account->getCreatedAt()->format(DateTimeInterface::ATOM),
                    $account->getUpdatedAt()->format(DateTimeInterface::ATOM),
                ]);
            }

            fclose($output);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="accounts.csv"');

        return $response;
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
