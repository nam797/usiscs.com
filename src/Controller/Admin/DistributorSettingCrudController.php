<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Distributor\DistributorRegistry;
use App\Distributor\DistributorSettings;
use App\Entity\Procurement\DistributorSetting;
use App\Repository\Procurement\PurchaseOrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-distributor switches: pause sending (e.g. while their system is down
 * for a day: orders wait queued instead of burning through retries), or
 * send everything for manual entry. Resuming sends the waiting orders
 * straight away. One row per distributor adapter, made automatically.
 *
 * @extends AbstractCrudController<DistributorSetting>
 */
class DistributorSettingCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly DistributorSettings $settings,
        private readonly DistributorRegistry $distributors,
        private readonly PurchaseOrderRepository $purchaseOrders,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return DistributorSetting::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Distributor')
            ->setEntityLabelInPlural('Distributors')
            ->setDefaultSort(['code' => 'ASC'])
        ;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE);
    }

    /** @param AdminContext<DistributorSetting> $context */
    public function index(AdminContext $context): KeyValueStore|Response
    {
        $this->settings->ensureAll();

        return parent::index($context);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('code', 'Distributor')->setDisabled()
            ->formatValue(fn (?string $code): string => sprintf('%s (%s)', $this->distributors->nameOf((string) $code), $code));
        yield BooleanField::new('paused')->renderAsSwitch(false)
            ->setHelp('Orders wait in the queue until you un-pause; then they are sent straight away.');
        yield BooleanField::new('manualEntry')->renderAsSwitch(false)
            ->setHelp('Email staff to place every order by hand, even if the distributor has an order API.');
        yield TextareaField::new('note')->setHelp('Why it is paused, who to call, etc.');
        yield DateTimeField::new('updatedAt')->hideOnForm();
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $wasPaused = true === ($entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance)['paused'] ?? false);
        if ($wasPaused && !$entityInstance->isPaused()) {
            // PurchaseOrderOutbox queues them after the flush below.
            foreach ($this->purchaseOrders->findQueuedFor($entityInstance->getCode()) as $purchaseOrder) {
                $purchaseOrder->setDispatchedAt(null);
            }
        }

        parent::updateEntity($entityManager, $entityInstance);
    }
}
