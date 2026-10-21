<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The /about page, carried over from the live usiscs.com/about. Purely
 * static content -- all copy lives in the template.
 */
final class AboutController extends AbstractController
{
    #[Route('/about', name: 'app_shop_about')]
    public function index(): Response
    {
        return $this->render('shop/about/index.html.twig');
    }
}
