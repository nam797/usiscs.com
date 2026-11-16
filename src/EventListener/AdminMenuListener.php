<?php

declare(strict_types=1);

namespace App\EventListener;

use Sylius\Bundle\AdminBundle\Menu\Provider\Main\EventMenuProvider;
use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Drops Sylius's "Official support" section (Sylius Plus, plugins,
 * partners, certification) from the admin sidebar: links to Sylius's own
 * sales pages, not needed by the client's staff.
 */
#[AsEventListener(event: EventMenuProvider::EVENT_NAME, method: 'onMainMenu')]
final class AdminMenuListener
{
    public function onMainMenu(MenuBuilderEvent $event): void
    {
        $event->getMenu()->removeChild('official_support');
    }
}
