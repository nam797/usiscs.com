<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The /terms page, carried over from the live usiscs.com/terms. Purely
 * static content -- all copy lives in the template.
 */
final class TermsController extends AbstractController
{
    #[Route('/terms', name: 'app_shop_terms')]
    public function index(): Response
    {
        return $this->render('shop/terms/index.html.twig');
    }
}
