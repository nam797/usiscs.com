<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Admin;

use App\Controller\Admin\ContactMessageCrudController;
use App\Controller\Admin\DashboardController;
use App\Controller\Admin\DistributorSettingCrudController;
use App\Controller\Admin\PurchaseOrderCrudController;
use App\Controller\Admin\RfqRequestCrudController;
use App\Controller\Admin\WarehouseCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Menu\MenuItemInterface;
use PHPUnit\Framework\TestCase;

final class DashboardControllerTest extends TestCase
{
    public function testItIsMountedAtBackofficeNotSyliusAdmin(): void
    {
        // /admin belongs to Sylius's own AdminBundle; mounting there would
        // shadow the catalog/order screens.
        $attribute = (new \ReflectionClass(DashboardController::class))->getAttributes(AdminDashboard::class)[0]->newInstance();

        self::assertSame('/backoffice', $attribute->routePath);
        self::assertSame('app_admin_dashboard', $attribute->routeName);
    }

    public function testItIsTitledForTheSite(): void
    {
        self::assertSame('USISCS', self::controller()->configureDashboard()->getAsDto()->getTitle());
    }

    public function testTheMenuLinksTheLeadQueuesAndSyliusAdmin(): void
    {
        $items = [];
        foreach (self::controller()->configureMenuItems() as $item) {
            self::assertInstanceOf(MenuItemInterface::class, $item);
            $items[] = $item->getAsDto();
        }

        $labels = [];
        foreach ($items as $dto) {
            $label = $dto->getLabel();
            self::assertIsString($label);
            $labels[] = $label;
        }

        self::assertSame(['Dashboard', 'RFQ Requests', 'Contact Messages', 'Warehouse', 'Purchase Orders', 'Distributors', 'Sylius Admin (catalog, orders)'], $labels);
        self::assertSame(RfqRequestCrudController::class, $items[1]->getRouteParameters()['crudControllerFqcn'] ?? null);
        self::assertSame(ContactMessageCrudController::class, $items[2]->getRouteParameters()['crudControllerFqcn'] ?? null);
        self::assertSame(PurchaseOrderCrudController::class, $items[4]->getRouteParameters()['crudControllerFqcn'] ?? null);
        self::assertSame(DistributorSettingCrudController::class, $items[5]->getRouteParameters()['crudControllerFqcn'] ?? null);
        self::assertSame('/admin', $items[6]->getLinkUrl());

        $warehouse = array_map(static fn ($item) => $item, $items[3]->getSubItems());
        self::assertSame(['Coming in', 'Due in the next 7 days', 'Overdue', 'Received, to ship on', 'All cross-dock'], array_map(static fn ($item): mixed => $item->getLabel(), $warehouse));
        foreach ($warehouse as $item) {
            self::assertSame(WarehouseCrudController::class, $item->getRouteParameters()['crudControllerFqcn'] ?? null);
        }
        self::assertSame(['warehouseStatus' => ['comparison' => '=', 'value' => 'overdue']], $warehouse[2]->getRouteParameters()['filters'] ?? null);
    }

    /** The menu and title don't use the dashboard summary, so no need to build one. */
    private static function controller(): DashboardController
    {
        return (new \ReflectionClass(DashboardController::class))->newInstanceWithoutConstructor();
    }
}
