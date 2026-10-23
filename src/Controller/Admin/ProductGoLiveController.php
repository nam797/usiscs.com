<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Catalog\GoLive\ReadinessChecker;
use App\Entity\Product\Product;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The "Go live" / "Take offline" bulk actions on the Sylius admin product
 * list (config/packages/app_admin_product_grid.yaml). Lives under the admin
 * path, so the admin firewall and access control already protect it.
 *
 * "Go live" only publishes products that pass the ReadinessChecker -- the
 * same rule as the import's go_live column -- and says which were skipped.
 */
final class ProductGoLiveController extends AbstractController
{
    public const CSRF_TOKEN_ID = 'app_admin_product_status';

    /** How many skipped products to name in the message before summarising. */
    private const MAX_LISTED = 10;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ReadinessChecker $readiness,
    ) {
    }

    #[Route('/%sylius_admin.path_name%/products/bulk/go-live', name: 'app_admin_product_bulk_go_live', methods: ['POST'])]
    public function goLive(Request $request): Response
    {
        $products = $this->selectedProducts($request);
        if (null === $products) {
            return $this->backToList($request);
        }

        $published = 0;
        $skipped = [];
        foreach ($products as $product) {
            if ($product->isEnabled()) {
                continue;
            }

            $problems = $this->readiness->problems($product);
            if ([] === $problems) {
                $product->setEnabled(true);
                ++$published;
            } else {
                $skipped[] = sprintf('%s (%s)', $product->getCode(), implode(', ', $problems));
            }
        }
        $this->entityManager->flush();

        if ($published > 0) {
            $this->addFlash('success', sprintf('%d product%s now live in the shop.', $published, 1 === $published ? ' is' : 's are'));
        }
        if ([] !== $skipped) {
            $listed = array_slice($skipped, 0, self::MAX_LISTED);
            $more = count($skipped) - count($listed);
            $this->addFlash('error', sprintf(
                'Not taken live, fix these first: %s%s',
                implode('; ', $listed),
                $more > 0 ? sprintf('; and %d more', $more) : '',
            ));
        }
        if (0 === $published && [] === $skipped) {
            $this->addFlash('info', 'The selected products were already live.');
        }

        return $this->backToList($request);
    }

    #[Route('/%sylius_admin.path_name%/products/bulk/take-offline', name: 'app_admin_product_bulk_take_offline', methods: ['POST'])]
    public function takeOffline(Request $request): Response
    {
        $products = $this->selectedProducts($request);
        if (null === $products) {
            return $this->backToList($request);
        }

        $count = 0;
        foreach ($products as $product) {
            if ($product->isEnabled()) {
                $product->setEnabled(false);
                ++$count;
            }
        }
        $this->entityManager->flush();

        $this->addFlash('success', 0 === $count
            ? 'The selected products were already offline.'
            : sprintf('%d product%s now hidden from the shop.', $count, 1 === $count ? ' is' : 's are'));

        return $this->backToList($request);
    }

    /** @return list<ProductInterface>|null null (with a flash message) when the request is unusable */
    private function selectedProducts(Request $request): ?array
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired; please try again.');

            return null;
        }

        $ids = [];
        foreach ($request->request->all('ids') as $id) {
            if (is_scalar($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }
        if ([] === $ids) {
            $this->addFlash('error', 'Select at least one product first.');

            return null;
        }

        $products = [];
        foreach ($this->entityManager->getRepository(Product::class)->findBy(['id' => $ids]) as $product) {
            if ($product instanceof ProductInterface) {
                $products[] = $product;
            }
        }

        return $products;
    }

    /** Back to the product list the admin came from, keeping its filters and page. */
    private function backToList(Request $request): RedirectResponse
    {
        $referer = (string) $request->headers->get('referer');
        $listUrl = $this->generateUrl('sylius_admin_product_index');

        if (str_starts_with($referer, $request->getSchemeAndHttpHost().$listUrl)) {
            return $this->redirect($referer);
        }

        return $this->redirect($listUrl);
    }
}
