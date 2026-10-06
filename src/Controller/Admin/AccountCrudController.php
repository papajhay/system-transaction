<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Account;
use App\Controller\Admin\BaseCrudController;
use App\Enum\StatusAccount;
use App\Enum\TypeAccount;
use App\Service\AccountNumberFilter;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Factory\FilterFactory;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Uid\Uuid;

final class AccountCrudController extends BaseCrudController
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
            ->add(
                AccountNumberFilter::new('accountNumber', 'Account number')
                    ->setChoices($this->getAccountNumberChoices())
            )
            ->add(
                ChoiceFilter::new('status', 'Status')
                    ->setChoices([
                        'Active' => StatusAccount::ACTIVE,
                        'Suspended' => StatusAccount::SUSPENDED,
                        'Closed' => StatusAccount::CLOSED,
            ])
        );
         
    }

    /**
     * @return array<string, string>
     */
    private function getAccountNumberChoices(): array
    {
        $entityManager = $this->container->get('doctrine')->getManagerForClass(Account::class);
        $accountNumbers = $entityManager->createQueryBuilder()
            ->select('DISTINCT account.accountNumber')
            ->from(Account::class, 'account')
            ->orderBy('account.accountNumber', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_combine($accountNumbers, $accountNumbers) ?: [];
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

    public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters,
    ): QueryBuilder {
        $queryBuilder = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        if ($searchDto->getRequest()->query->get('accountView') === 'suspended') {
            $queryBuilder
                ->andWhere('entity.status = :suspendedStatus')
                ->setParameter('suspendedStatus', StatusAccount::SUSPENDED->value);
        } else {
            $queryBuilder
                ->andWhere('entity.status != :suspendedStatus')
                ->setParameter('suspendedStatus', StatusAccount::SUSPENDED->value);
        }

        return $queryBuilder;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $this->configureCommonActions($actions)
            ->update(
                Crud::PAGE_INDEX,
                Action::EDIT,
                fn (Action $action): Action => $action->displayIf(fn (Account $account): bool => !$this->isSuspendedView())
            )
            ->update(
                Crud::PAGE_INDEX,
                Action::DELETE,
                fn (Action $action): Action => $action->displayIf(fn (Account $account): bool => !$this->isSuspendedView())
            )
            ->add(
                Crud::PAGE_INDEX,
                Action::new('restore', 'Restore', 'fa fa-rotate-left')
                    ->linkToCrudAction('restore')
                    ->displayIf(fn (Account $account): bool =>
                        $this->isSuspendedView()
                        && $account->getStatus() === StatusAccount::SUSPENDED
                    )
            )->add(
                Crud::PAGE_INDEX,
                Action::new('export', 'CSV Export', 'fa fa-file-csv')
                    ->createAsGlobalAction()
                    ->linkToCrudAction('export')
            );

        return $actions;
    }

    private function isSuspendedView(): bool
    {
        return $this->container->get('request_stack')->getCurrentRequest()?->query->get('accountView') === 'suspended';
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

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if (!$entityInstance instanceof Account) {
            throw new \InvalidArgumentException('Expected an account entity.');
        }

        if ($entityInstance->getStatus() !== StatusAccount::SUSPENDED) {
            $entityInstance->setPreviousStatus($entityInstance->getStatus());
        }

        $entityInstance
            ->setStatus(StatusAccount::SUSPENDED)
            ->setUpdatedAt(new DateTimeImmutable());

        $entityManager->persist($entityInstance);
        $entityManager->flush();
    }

    public function delete(AdminContext $context): Response
    {
        $response = parent::delete($context);
        $entity = $context->getEntity()->getInstance();

        if ($response->isRedirection() && $entity instanceof Account && $entity->getStatus() === StatusAccount::SUSPENDED) {
     
             $this->addFlash(
                'success',
                sprintf(
                    'Account "%s" suspended successfully.',
                    $entity->getAccountNumber()
                )
        );

            return $this->redirect($this->getAccountIndexUrl('suspended'));
        }

        return $response;
    }

    public function restore(AdminContext $context): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $entity = $context->getEntity()->getInstance();
        if (!$entity instanceof Account) {
            throw new \InvalidArgumentException('Expected an account entity.');
        }

        $entityManager = $this->container->get('doctrine')->getManagerForClass(Account::class);
        $this->restoreEntity($entityManager, $entity);
        $this->addFlash(
            'success',
            sprintf(
                'Account "%s" restored successfully.',
                $entity->getAccountNumber()
            )
        );

        return $this->redirect($this->getAccountIndexUrl('active'));
    }

    public function restoreEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if (!$entityInstance instanceof Account) {
            throw new \InvalidArgumentException('Expected an account entity.');
        }

        if ($entityInstance->getStatus() !== StatusAccount::SUSPENDED) {
            throw new \LogicException('Only suspended accounts can be restored.');
        }

        $entityInstance
            ->setStatus($entityInstance->getPreviousStatus() ?? StatusAccount::ACTIVE)
            ->setPreviousStatus(null)
            ->setUpdatedAt(new DateTimeImmutable());

        $entityManager->persist($entityInstance);
        $entityManager->flush();
    }

    private function getAccountIndexUrl(string $accountView): string
    {
        return $this->container->get(AdminUrlGenerator::class)
            ->setController(self::class)
            ->setAction(Crud::PAGE_INDEX)
            ->unset(EA::ENTITY_ID)
            ->set('accountView', $accountView)
            ->generateUrl();
    }
}