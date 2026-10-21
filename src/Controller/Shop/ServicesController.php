<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The /services page -- a single page with anchored sections, carried
 * over from the live usiscs.com/services. Purely static content (no
 * form, no entity), so this is just a render -- all copy lives in the
 * template.
 */
final class ServicesController extends AbstractController
{
    #[Route('/services', name: 'app_shop_services')]
    public function index(): Response
    {
        return $this->render('shop/services/index.html.twig');
    }
}
