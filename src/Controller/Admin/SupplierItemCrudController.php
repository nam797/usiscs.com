<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Catalog\Fulfilment\Fulfilment;
use App\Catalog\Stock\VariantStockTotal;
use App\Distributor\DistributorRegistry;
use App\Entity\Catalog\SupplierItem;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;

/**
 * Sources: which distributors carry each SKU, their SKU, fulfilment, what
 * they charge us and how many they have. Imports and the distributors'
 * syncs keep them up to date; staff confirm fulfilment, switch a source
 * off, or enter cost and stock for a distributor with no feed. New sources
 * come from a distributor's import sheet (its "distributor" column).
 *
 * @extends AbstractCrudController<SupplierItem>
 */
class SupplierItemCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly DistributorRegistry $distributors,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return SupplierItem::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Source')
            ->setEntityLabelInPlural('Sources')
            ->setDefaultSort(['sku' => 'ASC', 'supplier' => 'ASC'])
            ->setSearchFields(['sku', 'supplierSku', 'supplier'])
            ->setPageTitle(Crud::PAGE_EDIT, fn (SupplierItem $source): string => sprintf('%s from %s', $source->getSku(), $this->distributors->nameOf($source->getSupplier())))
            ->setHelp(Crud::PAGE_INDEX, 'Each paid order goes to the cheapest switched-on source with enough stock, never at or below cost. The shop\'s stock is the total over switched-on sources.')
        ;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW);
    }

    public function configureFilters(Filters $filters): Filters
    {
        $fulfilments = [];
        foreach (Fulfilment::cases() as $fulfilment) {
            $fulfilments[$fulfilment->label()] = $fulfilment->value;
        }

        return $filters
            ->add(ChoiceFilter::new('supplier')->setLabel('Distributor')->setChoices($this->distributors->choices()))
            ->add(ChoiceFilter::new('fulfilment')->setChoices($fulfilments))
            ->add('enabled')
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('sku')->setLabel('Our SKU')->hideOnForm();
        yield ChoiceField::new('supplier')->setLabel('Distributor')->setChoices($this->distributors->choices())->hideOnForm();
        yield TextField::new('supplierSku')->setLabel('Their SKU')
            ->setHelp('Blank when it\'s the same as ours.');
        yield ChoiceField::new('fulfilment')
            ->setChoices(array_combine(array_map(static fn (Fulfilment $f): string => $f->label(), Fulfilment::cases()), Fulfilment::cases()))
            ->renderAsBadges([Fulfilment::Unconfirmed->value => 'danger', Fulfilment::CrossDock->value => 'info', Fulfilment::OrsDirect->value => 'secondary']);
        yield BooleanField::new('enabled')->setLabel('On')
            ->setHelp('Switched off: no orders go here and its stock doesn\'t count.');
        yield MoneyField::new('costInCents')->setLabel('Our cost')->setCurrency('USD')->setStoredAsCents()->setRequired(false)
            ->setHelp('Per their selling unit. A price feed (ORS) overwrites it.');
        yield IntegerField::new('stock')->setRequired(false)
            ->setHelp('Blank = unknown. A stock feed (ORS) overwrites it.');
        yield TextField::new('unitSell')->setLabel('Unit')->onlyOnIndex();
        yield TextField::new('status')->setLabel('Distributor says')->hideOnForm();
        yield DateTimeField::new('stockSyncedAt')->setLabel('Stock updated')->hideOnForm()->hideOnIndex();
        yield DateTimeField::new('syncedAt')->setLabel('Cost updated')->hideOnForm()->hideOnIndex();
    }

    /** Stock edited by hand changes the shop's total too. */
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        VariantStockTotal::apply($entityInstance->getVariant());
        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $variant = $entityInstance->getVariant();
        $variant->removeSource($entityInstance);
        VariantStockTotal::apply($variant);
        parent::deleteEntity($entityManager, $entityInstance);
    }
}
