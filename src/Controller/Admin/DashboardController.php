<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\StatusAccount;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractDashboardController
{
    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        //return parent::index();
        return $this->render('admin/dashboard.html.twig');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('Transaction System');
    }

     public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard(
            'Dashboard',
            'fa fa-home'
        );

        yield MenuItem::subMenu('Account', 'fa fa-building-columns')
            ->setSubItems([
                MenuItem::linkTo(AccountCrudController::class, 'Accounts')
                    ->setQueryParameter('accountView', 'active'),
                MenuItem::linkTo(AccountCrudController::class, 'Suspended Account')
                    ->setQueryParameter('accountView', 'suspended'),
            ]);

        yield MenuItem::linkTo(CurrencyCrudController::class, 'Currencies', 'fa fa-coins');

        yield MenuItem::linkTo(ExchangeRateCrudController::class, 'Exchange rates', 'fa fa-arrow-right-arrow-left');

        yield MenuItem::linkTo(FeeCrudController::class, 'Fees', 'fa fa-money-bill');

        yield MenuItem::linkTo(ConversionCrudController::class, 'Conversions', 'fa fa-clipboard');

        yield MenuItem::linkTo(TransferCrudController::class, 'Transfers', 'fa fa-clipboard');

        yield MenuItem::linkTo(OperationCrudController::class, 'Operations', 'fa fa-list-check');
    }
}
