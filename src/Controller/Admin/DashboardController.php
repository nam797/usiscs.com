<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Menu\ControllerMenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use App\Controller\Admin\RfqRequestCrudController;
use App\Controller\Admin\ContactMessageCrudController;
use App\Admin\BackofficeSummary;
use App\Admin\Filter\WarehouseStatusFilter;
use App\Distributor\Warehouse\WarehouseStatus;
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
    public function __construct(
        private readonly BackofficeSummary $summary,
    ) {
    }

    /** What needs doing: the warehouse's deliveries, purchase orders to act on, new leads. */
    public function index(): Response
    {
        return $this->render('admin/backoffice/dashboard.html.twig', $this->summary->build(new \DateTimeImmutable('today')));
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
        yield MenuItem::subMenu('Warehouse', 'fa fa-dolly')->setSubItems([
            self::warehouseLink('Coming in', 'fa fa-truck-ramp-box', ['warehouseStatus' => ['comparison' => '=', 'value' => WarehouseStatusFilter::NOT_RECEIVED]]),
            self::warehouseLink('Due in the next 7 days', 'fa fa-calendar-week', [
                'warehouseStatus' => ['comparison' => '=', 'value' => WarehouseStatusFilter::NOT_RECEIVED],
                'expectedAt' => ['comparison' => 'between', 'value' => date('Y-m-d'), 'value2' => date('Y-m-d', strtotime('+6 days'))],
            ]),
            self::warehouseLink('Overdue', 'fa fa-triangle-exclamation', ['warehouseStatus' => ['comparison' => '=', 'value' => WarehouseStatus::Overdue->value]]),
            self::warehouseLink('Received, to ship on', 'fa fa-box', ['warehouseStatus' => ['comparison' => '=', 'value' => WarehouseStatus::Received->value]]),
            MenuItem::linkTo(WarehouseCrudController::class, 'All cross-dock', 'fa fa-list'),
        ]);
        yield MenuItem::linkTo(PurchaseOrderCrudController::class, 'Purchase Orders', 'fa fa-truck');
        yield MenuItem::linkTo(DistributorSettingCrudController::class, 'Distributors', 'fa fa-warehouse');
        yield MenuItem::linkTo(SupplierItemCrudController::class, 'Sources', 'fa fa-boxes-stacked');
        yield MenuItem::linkToUrl('Sylius Admin (catalog, orders)', 'fa fa-store', '/admin');
    }

    /**
     * The warehouse list with filters already set.
     *
     * @param array<string, array<string, string>> $filters
     */
    private static function warehouseLink(string $label, string $icon, array $filters): ControllerMenuItem
    {
        return MenuItem::linkTo(WarehouseCrudController::class, $label, $icon)->setQueryParameter('filters', $filters);
    }
}
