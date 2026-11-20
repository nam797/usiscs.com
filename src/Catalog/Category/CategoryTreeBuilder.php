<?php

declare(strict_types=1);

namespace App\Catalog\Category;

use App\Entity\Product\Product;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTaxonInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Taxonomy\Model\TaxonInterface as BaseTaxonInterface;
use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Puts the approved category tree (CategoryTree) in the database under the
 * menu's root taxon, moves products into it, and retires the launch
 * placeholders. Each step only does what is missing, so it can run again.
 */
final class CategoryTreeBuilder
{
    public const ROOT_CODE = 'category';
    public const ROOT_NAME = 'Products';

    /**
     * @param TaxonRepositoryInterface<TaxonInterface> $taxonRepository
     * @param FactoryInterface<TaxonInterface> $taxonFactory
     * @param FactoryInterface<ProductTaxonInterface> $productTaxonFactory
     * @param RepositoryInterface<LocaleInterface> $localeRepository
     */
    public function __construct(
        private readonly TaxonRepositoryInterface $taxonRepository,
        #[Autowire(service: 'sylius.factory.taxon')]
        private readonly FactoryInterface $taxonFactory,
        #[Autowire(service: 'sylius.factory.product_taxon')]
        private readonly FactoryInterface $productTaxonFactory,
        #[Autowire(service: 'sylius.repository.locale')]
        private readonly RepositoryInterface $localeRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Creates the root and every category of the tree that doesn't exist
     * yet. Never renames, moves or re-enables what is there, so an admin's
     * changes stay. When it creates departments, it puts the root's
     * children in the tree's order (placeholders last). Flushes.
     *
     * @return list<string> names of the categories created
     */
    public function createTree(): array
    {
        $locales = $this->localeCodes();
        $created = [];

        $root = $this->find(self::ROOT_CODE);
        if (null === $root) {
            $root = $this->createTaxon(self::ROOT_CODE, self::ROOT_NAME, 'products', $locales);
            $created[] = self::ROOT_NAME;
        }

        $slugger = new AsciiSlugger();
        $newDepartment = false;
        $walk = function (array $nodes, TaxonInterface $parent, ?string $parentSlug) use (&$walk, &$created, &$newDepartment, $slugger, $locales): void {
            foreach ($nodes as $code => [$name, , $children]) {
                $taxon = $this->find($code);
                $slug = (null === $parentSlug ? '' : $parentSlug.'/').$slugger->slug($name)->lower();
                if (null === $taxon) {
                    $taxon = $this->createTaxon($code, $name, $slug, $locales);
                    $taxon->setPosition(count($parent->getChildren()));
                    $parent->addChild($taxon);
                    $created[] = $name;
                    $newDepartment = $newDepartment || null === $parentSlug;
                }
                $walk($children, $taxon, (string) ($taxon->getTranslation($locales[0])->getSlug() ?? $slug));
            }
        };
        $walk(CategoryTree::TREE, $root, null);

        $this->entityManager->flush();

        if ($newDepartment) {
            $this->orderDepartments($root);
        }

        return $created;
    }

    /**
     * Moves a product into a category of the tree: that becomes its main
     * category and its only one. Does not flush.
     *
     * @return bool whether anything changed
     */
    public function moveProduct(ProductInterface $product, string $code): bool
    {
        $taxon = $this->find($code);
        if (null === $taxon) {
            throw new \RuntimeException(sprintf('Category "%s" does not exist; run app:catalog:setup-categories first.', $code));
        }

        $changed = $product->getMainTaxon() !== $taxon;
        $product->setMainTaxon($taxon);
        foreach ($product->getProductTaxons() as $productTaxon) {
            if ($productTaxon->getTaxon() !== $taxon) {
                $product->removeProductTaxon($productTaxon);
                $changed = true;
            }
        }
        if (!$product->hasTaxon($taxon)) {
            $productTaxon = $this->productTaxonFactory->createNew();
            $productTaxon->setTaxon($taxon);
            $product->addProductTaxon($productTaxon);
            $changed = true;
        }

        return $changed;
    }

    /**
     * Deletes the launch placeholder categories that no product uses any
     * more, and hides the ones that still have products (so they leave the
     * menu until those are moved). Flushes.
     *
     * @return array{removed: list<string>, hidden: list<string>}
     */
    public function retirePlaceholders(): array
    {
        $removed = [];
        $hidden = [];
        foreach (array_keys(CategoryTree::RETIRED) as $code) {
            $taxon = $this->find($code);
            if (null === $taxon) {
                continue;
            }
            if (0 === $this->productsUsing($taxon)) {
                $removed[] = (string) $taxon->getName();
                $this->entityManager->remove($taxon);
            } elseif ($taxon->isEnabled()) {
                $hidden[] = (string) $taxon->getName();
                $taxon->setEnabled(false);
            }
        }
        $this->entityManager->flush();

        return ['removed' => $removed, 'hidden' => $hidden];
    }

    /**
     * The root's children in the tree's order, anything else after them.
     * One flush per move: Gedmo's sortable shifts the siblings on each
     * position change, so setting them all at once scrambles the order.
     */
    public function orderDepartments(TaxonInterface $root): void
    {
        $order = array_flip(array_keys(CategoryTree::TREE));
        $children = $root->getChildren()->toArray();
        usort($children, static fn (BaseTaxonInterface $a, BaseTaxonInterface $b): int => ($order[(string) $a->getCode()] ?? PHP_INT_MAX) <=> ($order[(string) $b->getCode()] ?? PHP_INT_MAX));
        foreach ($children as $position => $child) {
            $child->setPosition($position);
            $this->entityManager->flush();
        }
    }

    public function find(string $code): ?TaxonInterface
    {
        return $this->taxonRepository->findOneBy(['code' => $code]);
    }

    private function productsUsing(TaxonInterface $taxon): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(DISTINCT product.id)')
            ->from(Product::class, 'product')
            ->leftJoin('product.productTaxons', 'productTaxon')
            ->where('product.mainTaxon = :taxon OR productTaxon.taxon = :taxon')
            ->setParameter('taxon', $taxon)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return non-empty-list<string> */
    private function localeCodes(): array
    {
        $codes = [];
        foreach ($this->localeRepository->findAll() as $locale) {
            if (null !== $locale->getCode()) {
                $codes[] = $locale->getCode();
            }
        }
        if ([] === $codes) {
            throw new \RuntimeException('No locales configured. Install Sylius (or load fixtures) first.');
        }

        return $codes;
    }

    /** @param non-empty-list<string> $locales */
    private function createTaxon(string $code, string $name, string $slug, array $locales): TaxonInterface
    {
        $taxon = $this->taxonFactory->createNew();
        $taxon->setCode($code);
        $taxon->setEnabled(true);
        $taxon->setCurrentLocale($locales[0]);
        $taxon->setFallbackLocale($locales[0]);
        foreach ($locales as $locale) {
            $translation = $taxon->getTranslation($locale);
            $translation->setName($name);
            $translation->setSlug($slug);
        }
        $this->entityManager->persist($taxon);

        return $taxon;
    }
}
