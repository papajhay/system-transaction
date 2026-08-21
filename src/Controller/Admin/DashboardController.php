<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Account;
use App\Entity\Currency;
use App\Entity\ExchangeRate;
use App\Entity\Fee;
use App\Entity\LedgerEntry;
use App\Entity\Operation;
use App\Entity\Transfer;
use App\Controller\Admin\AccountCrudController;
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

        yield MenuItem::linkToCrud(
            'Accounts',
            'fa fa-building-columns',
            Account::class
        );

        yield MenuItem::linkToCrud(
            'Currencies',
            'fa fa-coins',
            Currency::class
        );

        yield MenuItem::linkToCrud(
            'Exchange rates',
            'fa fa-arrow-right-arrow-left',
            ExchangeRate::class
        );

        yield MenuItem::linkToCrud(
            'Fees',
            'fa fa-money-bill',
            Fee::class
        );

        yield MenuItem::linkToCrud(
            'Ledger entries',
            'fa fa-clipboard',
            LedgerEntry::class
        );

        yield MenuItem::linkToCrud(
            'Transfers',
            'fa fa-clipboard',
            Transfer::class
        );

        yield MenuItem::linkToCrud(
            'Operations',
            'fa fa-list-check',
            Operation::class
        );
    }
}
