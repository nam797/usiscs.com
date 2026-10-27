<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Catalog\Fulfilment\Fulfilment;
use App\Distributor\DistributorRegistry;
use App\Distributor\PurchaseOrderEventType;
use App\Distributor\PurchaseOrderState;
use App\Distributor\PurchaseOrderStateMachine;
use App\Distributor\PurchaseOrderTransition;
use App\Entity\Procurement\PurchaseOrder;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Sylius\Component\Core\Model\AdminUserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Purchase orders to distributors, with each one's timeline. They're made
 * when an order is paid, never by hand; staff can fix the distributor or
 * fulfilment of one that needs attention, then Retry, Mark submitted (after
 * placing it themselves) or Cancel. Those go through the state machine, so
 * each only shows when it's allowed and lands on the timeline with who did it.
 *
 * @extends AbstractCrudController<PurchaseOrder>
 */
class PurchaseOrderCrudController extends AbstractCrudController
{
    private const ACTIONS = [
        'retry' => [PurchaseOrderTransition::Retry, 'Retry', 'Send it to the distributor again.'],
        'mark_submitted' => [PurchaseOrderTransition::MarkSubmitted, 'Mark submitted', 'You placed it with the distributor yourself.'],
        'cancel' => [PurchaseOrderTransition::Cancel, 'Cancel', 'Don\'t order these items from the distributor. This doesn\'t cancel or refund the shop order.'],
    ];

    public function __construct(
        private readonly PurchaseOrderStateMachine $stateMachine,
        private readonly DistributorRegistry $distributors,
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
            ->setEntityLabelInSingular('Purchase order')
            ->setEntityLabelInPlural('Purchase Orders')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['number', 'reference', 'distributor'])
            ->setPageTitle(Crud::PAGE_DETAIL, static fn (PurchaseOrder $purchaseOrder): string => 'Purchase order '.$purchaseOrder->getNumber())
        ;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $actions
            ->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $action): Action => $action->displayIf(static fn (PurchaseOrder $purchaseOrder): bool => self::isEditable($purchaseOrder)))
            ->update(Crud::PAGE_DETAIL, Action::EDIT, fn (Action $action): Action => $action
                ->setLabel('Fix distributor / fulfilment')
                ->displayIf(static fn (PurchaseOrder $purchaseOrder): bool => self::isEditable($purchaseOrder)));

        foreach (self::ACTIONS as $name => [$transition, $label]) {
            $method = lcfirst(str_replace('_', '', ucwords($name, '_')));
            $actions->add(Crud::PAGE_DETAIL, Action::new($name, $label)
                ->linkToCrudAction($method)
                ->displayIf(fn (PurchaseOrder $purchaseOrder): bool => $this->stateMachine->can($purchaseOrder, $transition)));
        }

        return $actions;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('state')->setChoices(array_flip(PurchaseOrderState::LABELS)))
            ->add(ChoiceFilter::new('distributor')->setChoices($this->distributors->choices() + ['Not set' => PurchaseOrder::UNASSIGNED]))
            ->add('createdAt')
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        $editing = Crud::PAGE_EDIT === $pageName;

        yield IdField::new('id')->hideOnForm()->hideOnIndex();
        yield TextField::new('number')->setLabel('PO number')->hideOnForm();
        yield Field::new('order')->setLabel('Shop order')->hideOnForm()
            ->setTemplatePath('admin/purchase_order/field/order.html.twig');
        yield ChoiceField::new('distributor')
            ->setChoices($this->distributors->choices() + ($editing ? [] : ['Not set' => PurchaseOrder::UNASSIGNED]));
        yield ChoiceField::new('fulfilment')
            ->setChoices(array_combine(array_map(static fn (Fulfilment $f): string => $f->label(), Fulfilment::cases()), Fulfilment::cases()));
        yield ChoiceField::new('state')->hideOnForm()
            ->setChoices(array_flip(PurchaseOrderState::LABELS))
            ->renderAsBadges([
                PurchaseOrderState::NEEDS_ATTENTION => 'danger',
                PurchaseOrderState::AWAITING_MANUAL_ENTRY => 'warning',
                PurchaseOrderState::ON_HOLD => 'warning',
                PurchaseOrderState::QUEUED => 'info',
                PurchaseOrderState::SUBMITTING => 'info',
                PurchaseOrderState::CANCELLED => 'secondary',
            ]);
        yield TextField::new('reference')->setLabel('Distributor order no.')->hideOnForm();
        yield IntegerField::new('attempts')->hideOnForm()->hideOnIndex();
        yield TextareaField::new('lastError')->hideOnForm()->hideOnIndex();
        yield DateTimeField::new('createdAt')->setLabel('Created')->hideOnForm();
        yield DateTimeField::new('submittedAt')->setLabel('Submitted')->hideOnForm()->hideOnIndex();
        yield Field::new('lines')->onlyOnDetail()->setTemplatePath('admin/purchase_order/field/lines.html.twig');
        yield Field::new('events')->setLabel('Timeline')->onlyOnDetail()->setTemplatePath('admin/purchase_order/field/events.html.twig');
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if (!self::isEditable($entityInstance)) {
            throw $this->createAccessDeniedException('Only a purchase order that needs attention can be edited.');
        }

        $original = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance);
        $originalDistributor = is_string($original['distributor'] ?? null) ? $original['distributor'] : '';
        $changes = [];
        if ($originalDistributor !== $entityInstance->getDistributor()) {
            $changes[] = sprintf('distributor %s → %s', $this->distributors->nameOf($originalDistributor), $this->distributors->nameOf($entityInstance->getDistributor()));
        }
        if (($original['fulfilment'] ?? null) !== $entityInstance->getFulfilment()) {
            $changes[] = sprintf('fulfilment → %s', $entityInstance->getFulfilment()->label());
        }
        if ([] !== $changes) {
            $entityInstance->log(PurchaseOrderEventType::Edited, 'Changed '.implode(', ', $changes).'. Click Retry to send it.', $this->actor());
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    /** @param AdminContext<PurchaseOrder> $context */
    #[AdminRoute('/{entityId}/retry', name: 'retry', options: ['methods' => ['GET', 'POST']])]
    public function retry(AdminContext $context, Request $request): Response
    {
        return $this->transition($context, $request, 'retry');
    }

    /** @param AdminContext<PurchaseOrder> $context */
    #[AdminRoute('/{entityId}/mark-submitted', name: 'mark_submitted', options: ['methods' => ['GET', 'POST']])]
    public function markSubmitted(AdminContext $context, Request $request): Response
    {
        return $this->transition($context, $request, 'mark_submitted');
    }

    /** @param AdminContext<PurchaseOrder> $context */
    #[AdminRoute('/{entityId}/cancel', name: 'cancel', options: ['methods' => ['GET', 'POST']])]
    public function cancel(AdminContext $context, Request $request): Response
    {
        return $this->transition($context, $request, 'cancel');
    }

    /**
     * GET shows a confirmation form (with a note, and the distributor's order number for "mark submitted"); POST applies it.
     *
     * @param AdminContext<PurchaseOrder> $context
     */
    private function transition(AdminContext $context, Request $request, string $name): Response
    {
        $purchaseOrder = $context->getEntity()->getInstance();
        if (!$purchaseOrder instanceof PurchaseOrder) {
            throw $this->createNotFoundException();
        }
        [$transition, $label, $help] = self::ACTIONS[$name];
        $detailUrl = $this->adminUrlGenerator->setController(self::class)->setAction(Action::DETAIL)->setEntityId($purchaseOrder->getId())->generateUrl();

        if (!$this->stateMachine->can($purchaseOrder, $transition)) {
            $this->addFlash('warning', sprintf('Can\'t %s a purchase order that is "%s".', strtolower($label), PurchaseOrderState::label($purchaseOrder->getState())));

            return $this->redirect($detailUrl);
        }

        $error = null;
        if ($request->isMethod('POST')) {
            $note = trim($request->request->getString('note'));
            $reference = trim($request->request->getString('reference'));

            if (!$this->isCsrfTokenValid('purchase_order_'.$name.'_'.$purchaseOrder->getId(), $request->request->getString('_token'))) {
                $error = 'The form expired. Please try again.';
            } elseif ('mark_submitted' === $name && '' === $reference) {
                $error = 'Enter the distributor\'s order number.';
            } else {
                if ('mark_submitted' === $name) {
                    $purchaseOrder->markSubmitted(mb_substr($reference, 0, 64));
                }
                if (PurchaseOrderTransition::Retry === $transition) {
                    $purchaseOrder->setDispatchedAt(null);
                }
                $message = 'mark_submitted' === $name ? 'Distributor order no. '.$reference.('' === $note ? '' : '. '.$note) : ('' === $note ? null : $note);
                $this->stateMachine->apply($purchaseOrder, $transition, $message, $this->actor());
                // A retried order is queued again by PurchaseOrderOutbox after this flush.
                $this->entityManager->flush();
                $this->addFlash('success', sprintf('Purchase order %s: %s.', $purchaseOrder->getNumber(), strtolower(PurchaseOrderEventType::of($transition)->label())));

                return $this->redirect($detailUrl);
            }
        }

        return $this->render('admin/purchase_order/transition.html.twig', [
            'purchase_order' => $purchaseOrder,
            'name' => $name,
            'label' => $label,
            'help' => $help,
            'error' => $error,
            'detail_url' => $detailUrl,
        ]);
    }

    private static function isEditable(PurchaseOrder $purchaseOrder): bool
    {
        return PurchaseOrderState::NEEDS_ATTENTION === $purchaseOrder->getState();
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
