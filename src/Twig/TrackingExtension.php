<?php

declare(strict_types=1);

namespace App\Twig;

use App\Shipping\TrackingLink;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Tracking numbers as carrier links, in the shipped email, the customer's
 * order page and the admin: `{% for link in tracking_links(shipment.tracking) %}`.
 */
final class TrackingExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('tracking_links', TrackingLink::all(...)),
        ];
    }
}
