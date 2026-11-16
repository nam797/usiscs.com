<?php

declare(strict_types=1);

namespace App\EventListener;

use Sylius\Bundle\AdminBundle\Menu\Provider\Main\EventMenuProvider;
use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Drops two Sylius sales links from the admin sidebar, not needed by the
 * client's staff: the "Official support" section (Sylius Plus, plugins,
 * partners, certification) and "Administration", whose only entry, Roles,
 * advertises Sylius Plus. Admin users are under Configuration →
 * Administrators.
 */
#[AsEventListener(event: EventMenuProvider::EVENT_NAME, method: 'onMainMenu')]
final class AdminMenuListener
{
    public function onMainMenu(MenuBuilderEvent $event): void
    {
        $event->getMenu()->removeChild('official_support');
        $event->getMenu()->removeChild('sylius.ui.administration');
    }
}
