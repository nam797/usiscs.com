<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Shipping\PackageProtectionTier;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;

/**
 * Package protection prices: each tier is a cart total and the fee from
 * there up to the next tier (TierPicker). Offered at checkout only while
 * the channel's "Offer package protection" switch is on (Sylius admin →
 * Channels).
 *
 * @extends AbstractCrudController<PackageProtectionTier>
 */
class PackageProtectionTierCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return PackageProtectionTier::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Package protection tier')
            ->setEntityLabelInPlural('Package protection')
            ->setDefaultSort(['minItemsTotal' => 'ASC'])
            ->setHelp(Crud::PAGE_INDEX, 'A cart pays the fee of the highest tier its items total reaches (after discounts, before shipping). Carts below the lowest tier aren\'t offered protection. It shows at checkout, ticked, only while "Offer package protection" is on for the channel (Sylius admin → Channels).')
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        yield MoneyField::new('minItemsTotal')->setLabel('Cart total from')->setCurrency('USD')->setStoredAsCents()
            ->setHelp('e.g. 100.00 for carts of $100 or more. Start the lowest tier at 0.00 to offer it on every cart.');
        yield MoneyField::new('fee')->setLabel('Fee')->setCurrency('USD')->setStoredAsCents();
    }
}
