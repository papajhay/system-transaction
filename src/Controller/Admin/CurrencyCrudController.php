<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Currency;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Factory\FilterFactory;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
            throw new \LogicException('The CSV export can only be used from the currency index page.');
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

            fputcsv($output, ['code', 'name', 'symbol']);

            /** @var Currency $currency */
            foreach ($queryBuilder->getQuery()->toIterable() as $currency) {
                fputcsv($output, [
                    $currency->getCode(),
                    $currency->getName(),
                    $currency->getSymbol(),
                ]);
            }

            fclose($output);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="currencies.csv"');

        return $response;
    }
}
