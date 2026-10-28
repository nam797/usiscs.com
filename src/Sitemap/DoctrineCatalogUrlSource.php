<?php

declare(strict_types=1);

namespace App\Sitemap;

use App\Entity\Product\Product;
use App\Entity\Product\ProductTaxon;
use App\Entity\Taxonomy\Taxon;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ChannelInterface;

/**
 * Plain DQL rather than Sylius's shop list query builders: those hydrate
 * full products (variants, prices, images) for listing pages, while the
 * sitemap only needs slugs and dates, and has to stay cheap as the catalog
 * grows.
 */
final class DoctrineCatalogUrlSource implements CatalogUrlSourceInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function products(ChannelInterface $channel, string $locale): array
    {
        /** @var list<array{slug: string, updatedAt: ?\DateTimeInterface}> $rows */
        $rows = $this->entityManager->createQuery(sprintf(
            'SELECT translation.slug AS slug, product.updatedAt AS updatedAt
             FROM %s product
             JOIN product.translations translation WITH translation.locale = :locale
             WHERE product.enabled = true
               AND :channel MEMBER OF product.channels
               AND translation.slug IS NOT NULL
             ORDER BY product.id ASC',
            Product::class,
        ))
            ->setParameter('locale', $locale)
            ->setParameter('channel', $channel)
            ->getArrayResult();

        return $rows;
    }

    /**
     * Every enabled category with a live product in it or anywhere below it,
     * including the root (/products, the whole catalog): a department whose
     * products all sit in its subcategories is still a page worth finding.
     */
    public function taxonSlugs(ChannelInterface $channel, string $locale): array
    {
        /** @var list<array{slug: string}> $rows */
        $rows = $this->entityManager->createQuery(sprintf(
            'SELECT translation.slug AS slug
             FROM %s taxon
             JOIN taxon.translations translation WITH translation.locale = :locale
             WHERE taxon.enabled = true
               AND translation.slug IS NOT NULL
               AND EXISTS (
                   SELECT 1 FROM %s productTaxon
                   JOIN productTaxon.product product
                   JOIN productTaxon.taxon productCategory
                   WHERE productCategory.root = taxon.root
                     AND productCategory.left >= taxon.left
                     AND productCategory.right <= taxon.right
                     AND product.enabled = true
                     AND :channel MEMBER OF product.channels
               )
             ORDER BY taxon.id ASC',
            Taxon::class,
            ProductTaxon::class,
        ))
            ->setParameter('locale', $locale)
            ->setParameter('channel', $channel)
            ->getArrayResult();

        return array_column($rows, 'slug');
    }
}
