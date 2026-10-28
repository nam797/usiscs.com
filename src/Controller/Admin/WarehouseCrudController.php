<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Filter\WarehouseStatusFilter;
use App\Catalog\Fulfilment\Fulfilment;
use App\Distributor\CustomerShipmentUpdater;
use App\Distributor\DistributorRegistry;
use App\Distributor\PurchaseOrderState;
use App\Entity\Procurement\PurchaseOrder;
use App\Repository\Procurement\PurchaseOrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Sylius\Component\Core\Model\AdminUserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The warehouse's view of cross-dock purchase orders: what the distributor
 * is sending to us, when it should arrive, and what's arrived but still has
 * to ship on to the customer. Staff set the expected date, Mark received
 * when it's checked in, then Ship to customer with our own tracking, which
 * goes on the customer's order (CustomerShipmentUpdater).
 *
 * Same rows as Purchase Orders, limited to live cross-dock ones; changing
 * the distributor or fulfilment is done there.
 *
 * @extends AbstractCrudController<PurchaseOrder>
 */
class WarehouseCrudController extends AbstractCrudController
{
    private const ACTIONS = [
        'set_expected_date' => ['Set expected date', 'When the delivery should reach our warehouse.'],
        'mark_received' => ['Mark received', 'The delivery is checked in at our warehouse. Note anything missing or damaged.'],
        'ship_to_customer' => ['Ship to customer', 'We shipped it on to the customer. The tracking number goes on their order; once nothing else is waiting, they get the "your order has shipped" email.'],
    ];

    public function __construct(
        private readonly DistributorRegistry $distributors,
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly CustomerShipmentUpdater $customerShipments,
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return PurchaseOrder::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Cross-dock delivery')
            ->setEntityLabelInPlural('Warehouse: cross-dock deliveries')
            ->setDefaultSort(['expectedAt' => 'ASC', 'createdAt' => 'ASC'])
            ->setSearchFields(['number', 'reference', 'order.number'])
            ->setPageTitle(Crud::PAGE_DETAIL, static fn (PurchaseOrder $purchaseOrder): string => 'Cross-dock delivery '.$purchaseOrder->getNumber())
            ->setHelp(Crud::PAGE_INDEX, 'Items the distributor ships to our warehouse for us to ship on. The expected date is the distributor\'s ship date plus a few business days until someone sets it.')
        ;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);

        foreach (self::ACTIONS as $name => [$label]) {
            $method = lcfirst(str_replace('_', '', ucwords($name, '_')));
            $action = fn (): Action => Action::new($name, $label)
                ->linkToCrudAction($method)
                ->displayIf(fn (PurchaseOrder $purchaseOrder): bool => $this->allows($purchaseOrder, $name));
            $actions->add(Crud::PAGE_INDEX, $action())->add(Crud::PAGE_DETAIL, $action());
        }

        return $actions;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(WarehouseStatusFilter::new())
            ->add('expectedAt')
            ->add(ChoiceFilter::new('distributor')->setChoices($this->distributors->choices()))
        ;
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->andWhere('entity.fulfilment = :warehouse_cross_dock')
            ->andWhere('entity.state <> :warehouse_cancelled')
            ->setParameter('warehouse_cross_dock', Fulfilment::CrossDock->value)
            ->setParameter('warehouse_cancelled', PurchaseOrderState::CANCELLED);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('number')->setLabel('PO number');
        yield Field::new('order')->setLabel('Shop order / ship to')
            ->setTemplatePath('admin/warehouse/field/order.html.twig');
        // A short list on the list page; the full table (SKUs, prices, shipped) on its page.
        yield Field::new('lines')->setLabel('Items')
            ->setTemplatePath(Crud::PAGE_DETAIL === $pageName ? 'admin/purchase_order/field/lines.html.twig' : 'admin/warehouse/field/items.html.twig');
        yield Field::new('warehouseStatus')->setLabel('Warehouse')->setSortable(false)
            ->setTemplatePath('admin/warehouse/field/status.html.twig');
        yield DateField::new('expectedAt')->setLabel('Expected')->setFormat('EEE, MMM d');
        yield Field::new('shipments')->setLabel('Distributor shipped')->setSortable(false)
            ->setTemplatePath('admin/warehouse/field/distributor_shipped.html.twig');
        yield ChoiceField::new('distributor')->setChoices($this->distributors->choices())->hideOnIndex();
        yield ChoiceField::new('state')->setLabel('Distributor status')
            ->setChoices(array_flip(PurchaseOrderState::LABELS));
        yield TextField::new('reference')->setLabel('Distributor order no.')->hideOnIndex();
        yield DateTimeField::new('receivedAt')->setLabel('Received')->onlyOnDetail();
        yield TextField::new('receivedBy')->setLabel('Received by')->onlyOnDetail();
        yield DateTimeField::new('forwardedAt')->setLabel('Shipped to customer')->onlyOnDetail();
        yield TextField::new('forwardedCarrier')->setLabel('Our carrier')->onlyOnDetail();
        yield TextField::new('forwardedTracking')->setLabel('Our tracking')->onlyOnDetail();
        yield Field::new('events')->setLabel('Timeline')->onlyOnDetail()->setTemplatePath('admin/purchase_order/field/events.html.twig');
    }

    /** @param AdminContext<PurchaseOrder> $context */
    #[AdminRoute('/{entityId}/set-expected-date', name: 'set_expected_date', options: ['methods' => ['GET', 'POST']])]
    public function setExpectedDate(AdminContext $context, Request $request): Response
    {
        return $this->act($context, $request, 'set_expected_date');
    }

    /** @param AdminContext<PurchaseOrder> $context */
    #[AdminRoute('/{entityId}/mark-received', name: 'mark_received', options: ['methods' => ['GET', 'POST']])]
    public function markReceived(AdminContext $context, Request $request): Response
    {
        return $this->act($context, $request, 'mark_received');
    }

    /** @param AdminContext<PurchaseOrder> $context */
    #[AdminRoute('/{entityId}/ship-to-customer', name: 'ship_to_customer', options: ['methods' => ['GET', 'POST']])]
    public function shipToCustomer(AdminContext $context, Request $request): Response
    {
        return $this->act($context, $request, 'ship_to_customer');
    }

    private function allows(PurchaseOrder $purchaseOrder, string $action): bool
    {
        if (!$purchaseOrder->isCrossDock() || PurchaseOrderState::CANCELLED === $purchaseOrder->getState()) {
            return false;
        }

        return match ($action) {
            'set_expected_date', 'mark_received' => null === $purchaseOrder->getReceivedAt(),
            'ship_to_customer' => null !== $purchaseOrder->getReceivedAt() && null === $purchaseOrder->getForwardedAt(),
            default => false,
        };
    }

    /**
     * GET shows a confirmation form; POST applies it.
     *
     * @param AdminContext<PurchaseOrder> $context
     */
    private function act(AdminContext $context, Request $request, string $name): Response
    {
        $purchaseOrder = $context->getEntity()->getInstance();
        if (!$purchaseOrder instanceof PurchaseOrder) {
            throw $this->createNotFoundException();
        }
        [$label, $help] = self::ACTIONS[$name];
        $detailUrl = $this->adminUrlGenerator->setController(self::class)->setAction(Action::DETAIL)->setEntityId($purchaseOrder->getId())->generateUrl();

        if (!$this->allows($purchaseOrder, $name)) {
            $this->addFlash('warning', sprintf('Can\'t %s for purchase order %s now.', strtolower($label), $purchaseOrder->getNumber()));

            return $this->redirect($detailUrl);
        }

        $error = null;
        if ($request->isMethod('POST')) {
            $note = trim($request->request->getString('note'));
            $expectedAt = self::parseDate($request->request->getString('expected_at'));
            $carrier = trim($request->request->getString('carrier'));
            $tracking = trim($request->request->getString('tracking'));

            if (!$this->isCsrfTokenValid('warehouse_'.$name.'_'.$purchaseOrder->getId(), $request->request->getString('_token'))) {
                $error = 'The form expired. Please try again.';
            } elseif ('set_expected_date' === $name && null === $expectedAt) {
                $error = 'Enter the date it should arrive.';
            } elseif ('ship_to_customer' === $name && ('' === $carrier || '' === $tracking)) {
                $error = 'Enter the carrier and the tracking number.';
            } else {
                if ('set_expected_date' === $name) {
                    $purchaseOrder->changeExpectedAt($expectedAt, $this->actor(), $note);
                } elseif ('mark_received' === $name) {
                    $purchaseOrder->markReceived($this->actor(), $note);
                } elseif ('ship_to_customer' === $name) {
                    $this->shipOn($purchaseOrder, $carrier, $tracking, $note);
                }
                $this->entityManager->flush();
                $this->addFlash('success', sprintf('Purchase order %s: %s.', $purchaseOrder->getNumber(), strtolower($label)));

                return $this->redirect($detailUrl);
            }
        }

        return $this->render('admin/warehouse/action.html.twig', [
            'purchase_order' => $purchaseOrder,
            'name' => $name,
            'label' => $label,
            'help' => $help,
            'error' => $error,
            'detail_url' => $detailUrl,
        ]);
    }

    private function shipOn(PurchaseOrder $purchaseOrder, string $carrier, string $tracking, string $note): void
    {
        $purchaseOrder->markForwarded($carrier, $tracking, $this->actor(), $note);
        $order = $purchaseOrder->getOrder();
        $this->customerShipments->update($order, $this->purchaseOrders->findForOrder($order));
    }

    /** "2026-10-30" from the date input; null when it isn't a real date. */
    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        if (1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return new \DateTimeImmutable($value);
    }

    /** Who did it, for the timeline: the admin's email. */
    private function actor(): string
    {
        $user = $this->getUser();
        if ($user instanceof AdminUserInterface && null !== $user->getEmail()) {
            return $user->getEmail();
        }

        return $user?->getUserIdentifier() ?? 'admin';
    }
}
