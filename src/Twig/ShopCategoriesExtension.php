<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Product\Product;
use App\Seo\CategoryFacts;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * - shop_categories(): the top-level categories of the shop menu (the same
 *   ones as the header's category row), in menu order, each with how many
 *   products a shopper can see in it. For the homepage's category tiles.
 * - related_products(product): other live products in the same category,
 *   for "More in <category>" under a product (instead of Sylius's
 *   unrelated "Latest products").
 */
final class ShopCategoriesExtension extends AbstractExtension
{
    /** @param TaxonRepositoryInterface<TaxonInterface> $taxons */
    public function __construct(
        #[Autowire(service: 'sylius.repository.taxon')]
        private readonly TaxonRepositoryInterface $taxons,
        private readonly ChannelContextInterface $channelContext,
        private readonly LocaleContextInterface $localeContext,
        private readonly CategoryFacts $facts,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('shop_categories', $this->shopCategories(...)),
            new TwigFunction('related_products', $this->relatedProducts(...)),
        ];
    }

    /** @return list<array{taxon: TaxonInterface, products: int}> */
    public function shopCategories(): array
    {
        $channel = $this->channelContext->getChannel();
        if (!$channel instanceof ChannelInterface) {
            return [];
        }

        $categories = [];
        foreach ($this->taxons->findChildrenByChannelMenuTaxon($channel->getMenuTaxon(), $this->localeContext->getLocaleCode()) as $taxon) {
            $categories[] = ['taxon' => $taxon, 'products' => $this->facts->productCount($taxon, $channel)];
        }

        return $categories;
    }

    /** @return list<ProductInterface> */
    public function relatedProducts(ProductInterface $product, int $limit = 4): array
    {
        $channel = $this->channelContext->getChannel();
        $category = $product->getMainTaxon();
        if (null === $category || !$channel instanceof ChannelInterface) {
            return [];
        }

        $related = [];
        foreach ($this->entityManager->createQueryBuilder()
            ->select('related')
            ->from(Product::class, 'related')
            ->where('related.mainTaxon = :category')
            ->andWhere('related.enabled = true')
            ->andWhere(':channel MEMBER OF related.channels')
            ->andWhere('related.id != :product')
            ->setParameter('category', $category)
            ->setParameter('channel', $channel)
            ->setParameter('product', $product->getId())
            ->orderBy('related.code')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult() as $candidate) {
            $related[] = $candidate;
        }

        return $related;
    }
}
