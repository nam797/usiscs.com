<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use App\Controller\Admin\RfqRequestCrudController;
use App\Controller\Admin\ContactMessageCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

// Mounted at /backoffice, not /admin — Sylius's own AdminBundle already
// owns /admin (catalog, orders, customers, etc.; see
// config/routes/sylius_admin.yaml). This dashboard is for app-specific
// screens that aren't Sylius resources, starting with the RFQ queue
// (see the RFQ Flow Spec doc for the planned RfqRequest entity).
#[AdminDashboard(routePath: '/backoffice', routeName: 'app_admin_dashboard')]
class DashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        return parent::index();
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('USISCS');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        yield MenuItem::linkTo(RfqRequestCrudController::class, 'RFQ Requests', 'fa fa-file-invoice');
        yield MenuItem::linkTo(ContactMessageCrudController::class, 'Contact Messages', 'fa fa-envelope');
        yield MenuItem::linkTo(PurchaseOrderCrudController::class, 'Purchase Orders', 'fa fa-truck');
        yield MenuItem::linkTo(DistributorSettingCrudController::class, 'Distributors', 'fa fa-warehouse');
        yield MenuItem::linkToUrl('Sylius Admin (catalog, orders)', 'fa fa-store', '/admin');
    }
}
