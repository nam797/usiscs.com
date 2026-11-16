<?php

declare(strict_types=1);

namespace App\Admin\Filter;

use App\Shipping\PackageProtection\PackageProtectionOrderProcessor;
use Sylius\Bundle\GridBundle\Form\Type\Filter\SelectFilterType;
use Sylius\Component\Grid\Attribute\AsFilter;
use Sylius\Component\Grid\Data\DataSourceInterface;
use Sylius\Component\Grid\Filtering\FilterInterface;

/**
 * "Package protection" filter on the Sylius admin order list
 * (config/packages/app_admin_order_grid.yaml), for claims:
 *  - protected: the order paid the fee (it has the package_protection adjustment)
 *  - declined: the customer unticked the box
 * Orders it was never offered to match neither.
 */
#[AsFilter(formType: SelectFilterType::class, type: 'app_package_protection', template: '@SyliusAdmin/shared/grid/filter/select.html.twig')]
final class PackageProtectionGridFilter implements FilterInterface
{
    public const PROTECTED = 'protected';
    public const DECLINED = 'declined';

    public function apply(DataSourceInterface $dataSource, string $name, $data, array $options): void
    {
        $expressions = $dataSource->getExpressionBuilder();

        match ($data) {
            self::PROTECTED => $dataSource->restrict($expressions->equals('adjustments.type', PackageProtectionOrderProcessor::ADJUSTMENT_TYPE)),
            self::DECLINED => $dataSource->restrict($expressions->equals('packageProtection', false)),
            default => null,
        };
    }
}
