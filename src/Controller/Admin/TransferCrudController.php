<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Transfer;
use App\Enum\StatusTransfer;
use DateTimeInterface;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Factory\FilterFactory;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\ComparisonType;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(DateTimeFilter::new('createdAt', 'Created At'))
            ->add(
                ChoiceFilter::new('status', 'Status')
                    ->setChoices([
                        'Pending' => StatusTransfer::PENDING,
                        'Completed' => StatusTransfer::COMPLETED,
                        'Failed' => StatusTransfer::FAILED,
                    ])
            );
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
        return $actions
            ->disable(Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(
                Crud::PAGE_INDEX,
                Action::new('view_operation', 'View operation', 'fa fa-eye')
                    ->linkToCrudAction('viewOperation')
            )
            ->add(
                Crud::PAGE_INDEX,
                Action::new('export', 'CSV Export', 'fa fa-file-csv')
                    ->createAsGlobalAction()
                    ->linkToCrudAction('export')
            );
    }

    public function viewOperation(AdminContext $context): RedirectResponse
    {
        /** @var Transfer $transfer */
        $transfer = $context->getEntity()->getInstance();

        $url = $this->container->get(AdminUrlGenerator::class)
            ->setController(OperationCrudController::class)
            ->setAction(Crud::PAGE_INDEX)
            ->set(EA::FILTERS, [
                'transfer' => [
                    'comparison' => ComparisonType::EQ,
                    'value' => $transfer->getId(),
                ],
            ])
            ->generateUrl();

        return $this->redirect($url);
    }

    public function export(AdminContext $context): StreamedResponse
    {
        $search = $context->getSearch();
        if (null === $search) {
            throw new \LogicException('The CSV export can only be used from the transfer index page.');
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
                'id',
                'token',
                'reference',
                'sender_account',
                'receiver_account',
                'amount',
                'currency',
                'received_amount',
                'received_currency',
                'exchange_rate',
                'type',
                'status',
                'description',
                'processed_at',
                'expires_at',
                'created_at',
                'updated_at',
            ]);

            /** @var Transfer $transfer */
            foreach ($queryBuilder->getQuery()->toIterable() as $transfer) {
                fputcsv($output, [
                    $transfer->getId(),
                    $transfer->getToken(),
                    $transfer->getReference(),
                    $transfer->getSenderAccount()?->getAccountNumber() ?? '',
                    $transfer->getReceiverAccount()?->getAccountNumber() ?? '',
                    $transfer->getAmount(),
                    $transfer->getCurrency()?->getCode() ?? '',
                    $transfer->getReceivedAmount(),
                    $transfer->getReceivedCurrency()?->getCode() ?? '',
                    $transfer->getExchangeRate(),
                    $transfer->getType()->value,
                    $transfer->getStatus()->value,
                    $transfer->getDescription() ?? '',
                    $transfer->getProcessedAt()?->format(DateTimeInterface::ATOM) ?? '',
                    $transfer->getExpiresAt()?->format(DateTimeInterface::ATOM) ?? '',
                    $transfer->getCreatedAt()->format(DateTimeInterface::ATOM),
                    $transfer->getUpdatedAt()->format(DateTimeInterface::ATOM),
                ]);
            }

            fclose($output);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="transfers.csv"');

        return $response;
    }
}
