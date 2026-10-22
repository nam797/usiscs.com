<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The /privacy page. NOTE: the live site's own footer links to
 * usiscs.com/privacy, but that page 404s on the live site -- it was never
 * actually published, and isn't in the live sitemap. There is no existing
 * copy to carry over. This is a draft policy written from how this app
 * actually handles data (RFQ/Contact forms, Stripe checkout) -- it needs
 * review by the client/counsel before launch, not just a code review.
 */
final class PrivacyController extends AbstractController
{
    #[Route('/privacy', name: 'app_shop_privacy')]
    public function index(): Response
    {
        return $this->render('shop/privacy/index.html.twig');
    }
}
