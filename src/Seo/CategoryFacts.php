<?php

declare(strict_types=1);

namespace App\Seo;

use App\Entity\Product\Product;
use App\Entity\Product\ProductAttributeValue;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\TaxonInterface;

/**
 * What's in a category, for its meta description: how many live products
 * it has (in it or anywhere below it) and its most common brands. Plain
 * DQL over the category tree's left/right values, like the sitemap.
 */
final class CategoryFacts
{
    /** The product attribute imports fill with the brand (ProductImporter::ATTRIBUTES). */
    private const MANUFACTURER = 'manufacturer';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function productCount(TaxonInterface $taxon, ChannelInterface $channel): int
    {
        return (int) $this->liveProductsIn($taxon, $channel)
            ->select('COUNT(DISTINCT product.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<string> the most common manufacturers, most products first */
    public function topBrands(TaxonInterface $taxon, ChannelInterface $channel, string $locale, int $limit = 3): array
    {
        /** @var list<array{brand: string}> $rows */
        $rows = $this->liveProductsIn($taxon, $channel)
            ->select('value.text AS brand', 'COUNT(DISTINCT product.id) AS HIDDEN products')
            ->join(ProductAttributeValue::class, 'value', 'WITH', 'value.subject = product AND value.localeCode = :locale')
            ->join('value.attribute', 'attribute', 'WITH', 'attribute.code = :manufacturer')
            ->andWhere('value.text IS NOT NULL')
            ->setParameter('locale', $locale)
            ->setParameter('manufacturer', self::MANUFACTURER)
            ->groupBy('value.text')
            ->orderBy('products', 'DESC')
            ->addOrderBy('value.text', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_values(array_filter(array_map(static fn (array $row): string => trim($row['brand']), $rows)));
    }

    private function liveProductsIn(TaxonInterface $taxon, ChannelInterface $channel): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->from(Product::class, 'product')
            ->join('product.productTaxons', 'productTaxon')
            ->join('productTaxon.taxon', 'category')
            ->where('product.enabled = true')
            ->andWhere(':channel MEMBER OF product.channels')
            ->andWhere('category.root = :root AND category.left >= :left AND category.right <= :right')
            ->setParameter('channel', $channel)
            ->setParameter('root', $taxon->getRoot() ?? $taxon)
            ->setParameter('left', $taxon->getLeft())
            ->setParameter('right', $taxon->getRight());
    }
}
