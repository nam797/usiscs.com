<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Admin;

use App\Controller\Admin\ContactMessageCrudController;
use App\Controller\Admin\DashboardController;
use App\Controller\Admin\DistributorSettingCrudController;
use App\Controller\Admin\PurchaseOrderCrudController;
use App\Controller\Admin\RfqRequestCrudController;
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
        self::assertSame('USISCS', (new DashboardController())->configureDashboard()->getAsDto()->getTitle());
    }

    public function testTheMenuLinksTheLeadQueuesAndSyliusAdmin(): void
    {
        $items = [];
        foreach ((new DashboardController())->configureMenuItems() as $item) {
            self::assertInstanceOf(MenuItemInterface::class, $item);
            $items[] = $item->getAsDto();
        }

        $labels = [];
        foreach ($items as $dto) {
            $label = $dto->getLabel();
            self::assertIsString($label);
            $labels[] = $label;
        }

        self::assertSame(['Dashboard', 'RFQ Requests', 'Contact Messages', 'Purchase Orders', 'Distributors', 'Sylius Admin (catalog, orders)'], $labels);
        self::assertSame(RfqRequestCrudController::class, $items[1]->getRouteParameters()['crudControllerFqcn'] ?? null);
        self::assertSame(ContactMessageCrudController::class, $items[2]->getRouteParameters()['crudControllerFqcn'] ?? null);
        self::assertSame(PurchaseOrderCrudController::class, $items[3]->getRouteParameters()['crudControllerFqcn'] ?? null);
        self::assertSame(DistributorSettingCrudController::class, $items[4]->getRouteParameters()['crudControllerFqcn'] ?? null);
        self::assertSame('/admin', $items[5]->getLinkUrl());
    }
}
