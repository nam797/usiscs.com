<?php

declare(strict_types=1);

namespace App\Catalog\Import;

use App\Catalog\GoLive\ReadinessChecker;
use App\Entity\Catalog\ProductImportState;
use App\Entity\Channel\Channel;
use App\Entity\Locale\Locale;
use App\Entity\Product\Product;
use App\Entity\Product\ProductAttribute;
use App\Entity\Product\ProductTranslation;
use App\Entity\Taxonomy\Taxon;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Attribute\Factory\AttributeFactoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductImageInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTaxonInterface;
use Sylius\Component\Core\Model\ProductTranslationInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Core\Uploader\ImageUploaderInterface;
use Sylius\Component\Product\Factory\ProductFactoryInterface;
use Sylius\Component\Product\Model\ProductAttributeInterface;
use Sylius\Component\Product\Model\ProductAttributeValueInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Turns rows from any ProductSourceInterface into Sylius products.
 *
 * - New SKU: created as a hidden draft (enabled only if the row says
 *   go_live=yes and the product passes the ReadinessChecker).
 * - SKU created by an earlier import: updated field by field, keeping any
 *   field an admin has changed (FieldMerger). Never changes whether it's live.
 * - SKU that exists but was made by hand: left untouched.
 *
 * Bad rows are reported and skipped; they never stop the rest of the import.
 */
final class ProductImporter
{
    private const BATCH_SIZE = 100;

    /** Text attributes the import manages, by product attribute code. */
    public const ATTRIBUTES = [
        'manufacturer' => 'Manufacturer',
        'part_number' => 'Part number',
        'unit_of_measure' => 'Unit of measure',
    ];

    /** @var array<string, array<string, true>> slugs handed out during this run, per locale */
    private array $reservedSlugs = [];

    private readonly AsciiSlugger $slugger;

    /**
     * @param FactoryInterface<ProductTaxonInterface>          $productTaxonFactory
     * @param FactoryInterface<ChannelPricingInterface>        $channelPricingFactory
     * @param AttributeFactoryInterface<ProductAttributeInterface> $attributeFactory
     * @param FactoryInterface<ProductAttributeValueInterface> $attributeValueFactory
     * @param FactoryInterface<ProductImageInterface>          $imageFactory
     * @param ProductFactoryInterface<ProductInterface>        $productFactory
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'sylius.factory.product')]
        private readonly ProductFactoryInterface $productFactory,
        #[Autowire(service: 'sylius.factory.product_taxon')]
        private readonly FactoryInterface $productTaxonFactory,
        #[Autowire(service: 'sylius.factory.channel_pricing')]
        private readonly FactoryInterface $channelPricingFactory,
        #[Autowire(service: 'sylius.factory.product_attribute')]
        private readonly AttributeFactoryInterface $attributeFactory,
        #[Autowire(service: 'sylius.factory.product_attribute_value')]
        private readonly FactoryInterface $attributeValueFactory,
        #[Autowire(service: 'sylius.factory.product_image')]
        private readonly FactoryInterface $imageFactory,
        private readonly ImageUploaderInterface $imageUploader,
        private readonly ProductImageFetcher $imageFetcher,
        private readonly ReadinessChecker $readiness,
        private readonly FieldMerger $merger,
        #[Autowire('%locale%')]
        private readonly string $defaultLocale,
    ) {
        $this->slugger = new AsciiSlugger();
    }

    /**
     * @throws \RuntimeException when the source can't be read at all
     */
    public function import(ProductSourceInterface $source, bool $dryRun = false): ImportReport
    {
        $report = new ImportReport($dryRun);
        $this->reservedSlugs = [];
        $seen = [];
        $processed = 0;

        // A dry run does all the work inside a transaction and rolls it back,
        // so its report is exactly what a real run would do.
        $connection = $this->entityManager->getConnection();
        if ($dryRun) {
            $connection->beginTransaction();
        }

        try {
            foreach ($source->rows() as $rowNumber => $values) {
                try {
                    $row = ProductRow::fromArray($values);
                } catch (InvalidProductRow $e) {
                    ++$report->failed;
                    foreach ($e->problems as $problem) {
                        $report->add($rowNumber, self::skuOf($values), ImportReport::ERROR, $problem);
                    }
                    continue;
                }

                if (isset($seen[$row->sku])) {
                    ++$report->failed;
                    $report->add($rowNumber, $row->sku, ImportReport::ERROR, sprintf('duplicate SKU, already on row %d; this row was skipped', $seen[$row->sku]));
                    continue;
                }
                $seen[$row->sku] = $rowNumber;

                $this->importRow($row, $rowNumber, $source->name(), $dryRun, $report);

                if (0 === ++$processed % self::BATCH_SIZE) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                }
            }

            $this->entityManager->flush();
            $this->entityManager->clear();
        } finally {
            if ($dryRun && $connection->isTransactionActive()) {
                $connection->rollBack();
                $this->entityManager->clear();
            }
        }

        return $report;
    }

    private function importRow(ProductRow $row, int $rowNumber, string $sourceName, bool $dryRun, ImportReport $report): void
    {
        $taxon = $this->entityManager->getRepository(Taxon::class)->findOneBy(['code' => $row->categoryCode]);
        if (!$taxon instanceof TaxonInterface) {
            ++$report->failed;
            $report->add($rowNumber, $row->sku, ImportReport::ERROR, sprintf('category "%s" is not set up yet; run app:catalog:setup-categories first', $row->categoryCode));

            return;
        }

        $product = $this->entityManager->getRepository(Product::class)->findOneBy(['code' => $row->sku]);
        $state = $this->entityManager->getRepository(ProductImportState::class)->findOneBy(['productCode' => $row->sku]);

        if (!$product instanceof ProductInterface) {
            $this->create($row, $rowNumber, $sourceName, $state, $dryRun, $report);

            return;
        }

        if (null === $state) {
            ++$report->untouched;
            $report->add($rowNumber, $row->sku, ImportReport::INFO, 'already exists and was not created by an import; left as it is');

            return;
        }

        $this->update($product, $row, $rowNumber, $sourceName, $state, $report);
    }

    private function create(ProductRow $row, int $rowNumber, string $sourceName, ?ProductImportState $state, bool $dryRun, ImportReport $report): void
    {
        $product = $this->productFactory->createWithVariant();
        $product->setCode($row->sku);
        $product->setEnabled(false);
        $product->setCurrentLocale($this->defaultLocale);
        $product->setFallbackLocale($this->defaultLocale);

        foreach ($this->localeCodes() as $locale) {
            $translation = $product->getTranslation($locale);
            $translation->setName($row->name);
            $translation->setSlug($this->uniqueSlug($row->name, $row->sku, $locale));
        }

        $variant = $product->getVariants()->first();
        \assert($variant instanceof ProductVariantInterface);
        $variant->setCode($row->sku);
        $variant->setTracked(false);

        $channelCodes = [];
        foreach ($this->entityManager->getRepository(Channel::class)->findAll() as $channel) {
            $product->addChannel($channel);
            $channelCodes[] = (string) $channel->getCode();
        }

        $incoming = $this->incoming($row, $channelCodes);
        $this->write($product, $variant, $incoming);

        if (null !== $row->imageUrl) {
            if ($dryRun) {
                $report->add($rowNumber, $row->sku, ImportReport::INFO, 'image not downloaded in a dry run');
            } else {
                $this->attachImage($product, $row->imageUrl, $rowNumber, $report);
            }
        }

        $this->entityManager->persist($product);

        if (null === $state) {
            $this->entityManager->persist(new ProductImportState($row->sku, $sourceName, $incoming));
        } else {
            // The product was imported before and then deleted in the admin.
            $state->recordImport($sourceName, $incoming);
        }

        ++$report->created;

        if (!$row->goLive) {
            $report->add($rowNumber, $row->sku, ImportReport::INFO, 'created as a hidden draft');

            return;
        }

        $problems = $this->readiness->problems($product);
        if ([] === $problems) {
            $product->setEnabled(true);
            ++$report->createdLive;
            $report->add($rowNumber, $row->sku, ImportReport::INFO, 'created and live');
        } else {
            $report->add($rowNumber, $row->sku, ImportReport::WARNING, sprintf('created as a hidden draft; go_live needs: %s', implode(', ', $problems)));
        }
    }

    private function update(ProductInterface $product, ProductRow $row, int $rowNumber, string $sourceName, ProductImportState $state, ImportReport $report): void
    {
        $variant = $this->variantOf($product, $row->sku);
        if (null === $variant) {
            ++$report->untouched;
            $report->add($rowNumber, $row->sku, ImportReport::WARNING, 'has no variant to update; left as it is');

            return;
        }

        $channelCodes = [];
        foreach ($product->getChannels() as $channel) {
            $channelCodes[] = (string) $channel->getCode();
        }

        $incoming = $this->incoming($row, $channelCodes);
        $result = $this->merger->merge($state->getImportedValues(), $this->current($product, $variant, array_keys($incoming)), $incoming);

        $this->write($product, $variant, $result->toWrite);
        $state->recordImport($sourceName, $result->snapshot);

        if ([] === $result->toWrite) {
            ++$report->unchanged;
        } else {
            ++$report->updated;
            $report->add($rowNumber, $row->sku, ImportReport::INFO, sprintf('updated %s', self::fieldList(array_keys($result->toWrite))));
        }

        if ([] !== $result->kept) {
            $report->add($rowNumber, $row->sku, ImportReport::INFO, sprintf('kept admin edits to %s', self::fieldList($result->kept)));
        }

        if ($row->goLive && !$product->isEnabled()) {
            $report->add($rowNumber, $row->sku, ImportReport::INFO, 'go_live only applies to new products; use "Go live" in the admin product list');
        }
    }

    /**
     * The fields this row sets, as scalar values comparable with current().
     *
     * @param list<string> $channelCodes
     *
     * @return array<string, scalar|null>
     */
    private function incoming(ProductRow $row, array $channelCodes): array
    {
        $values = ['name' => $row->name, 'category' => $row->categoryCode];

        $optional = [
            'description' => $row->description,
            'short_description' => $row->shortDescription,
            'manufacturer' => $row->manufacturer,
            'part_number' => $row->partNumber,
            'unit_of_measure' => $row->unitOfMeasure,
        ];
        foreach ($optional as $column => $value) {
            if ($row->provides($column)) {
                $values[$column] = $value;
            }
        }

        if ($row->provides('stock_qty')) {
            $values['stock'] = $row->stockQty;
        }

        foreach ($channelCodes as $code) {
            $values['price:'.$code] = $row->priceInCents;
        }

        return $values;
    }

    /**
     * @param list<string> $fields
     *
     * @return array<string, scalar|null>
     */
    private function current(ProductInterface $product, ProductVariantInterface $variant, array $fields): array
    {
        $translation = $product->getTranslation($this->defaultLocale);
        $current = [];

        foreach ($fields as $field) {
            $current[$field] = match (true) {
                'name' === $field => $translation->getName(),
                'description' === $field => $translation->getDescription(),
                'short_description' === $field => $translation->getShortDescription(),
                'category' === $field => $product->getMainTaxon()?->getCode(),
                'stock' === $field => $variant->isTracked() ? $variant->getOnHand() : null,
                isset(self::ATTRIBUTES[$field]) => $this->attributeValue($product, $field),
                str_starts_with($field, 'price:') => $this->pricing($variant, substr($field, 6))?->getPrice(),
                default => null,
            };
        }

        return $current;
    }

    /** @param array<string, scalar|null> $values */
    private function write(ProductInterface $product, ProductVariantInterface $variant, array $values): void
    {
        foreach ($values as $field => $value) {
            $text = null === $value ? null : (string) $value;

            match (true) {
                'name' === $field => $this->eachTranslation($product, static fn ($t) => $t->setName($text)),
                'description' === $field => $this->eachTranslation($product, static fn ($t) => $t->setDescription($text)),
                'short_description' === $field => $this->eachTranslation($product, static fn ($t) => $t instanceof ProductTranslationInterface ? $t->setShortDescription($text) : null),
                'category' === $field => $this->setCategory($product, (string) $text),
                'stock' === $field => $this->setStock($variant, null === $value ? null : (int) $value),
                isset(self::ATTRIBUTES[$field]) => $this->setAttribute($product, $field, $text),
                str_starts_with($field, 'price:') => $this->setPrice($variant, substr($field, 6), (int) $value),
                default => null,
            };
        }
    }

    /** @param callable(\Sylius\Component\Product\Model\ProductTranslationInterface): mixed $change */
    private function eachTranslation(ProductInterface $product, callable $change): void
    {
        foreach ($this->localeCodes() as $locale) {
            $change($product->getTranslation($locale));
        }
    }

    private function setCategory(ProductInterface $product, string $code): void
    {
        $taxon = $this->entityManager->getRepository(Taxon::class)->findOneBy(['code' => $code]);
        if (!$taxon instanceof TaxonInterface) {
            return;
        }

        $previous = $product->getMainTaxon();
        $product->setMainTaxon($taxon);

        if (null !== $previous && $previous !== $taxon) {
            foreach ($product->getProductTaxons() as $productTaxon) {
                if ($productTaxon->getTaxon() === $previous) {
                    $product->removeProductTaxon($productTaxon);
                }
            }
        }

        if (!$product->hasTaxon($taxon)) {
            $productTaxon = $this->productTaxonFactory->createNew();
            $productTaxon->setTaxon($taxon);
            $product->addProductTaxon($productTaxon);
        }
    }

    private function setStock(ProductVariantInterface $variant, ?int $quantity): void
    {
        if (null === $quantity) {
            $variant->setTracked(false);

            return;
        }

        $variant->setTracked(true);
        $variant->setOnHand($quantity);
    }

    private function setPrice(ProductVariantInterface $variant, string $channelCode, int $price): void
    {
        $pricing = $this->pricing($variant, $channelCode);
        if (null === $pricing) {
            $pricing = $this->channelPricingFactory->createNew();
            $pricing->setChannelCode($channelCode);
            $variant->addChannelPricing($pricing);
        }

        $pricing->setPrice($price);
    }

    private function pricing(ProductVariantInterface $variant, string $channelCode): ?ChannelPricingInterface
    {
        foreach ($variant->getChannelPricings() as $pricing) {
            if ($pricing->getChannelCode() === $channelCode) {
                return $pricing;
            }
        }

        return null;
    }

    private function attributeValue(ProductInterface $product, string $code): ?string
    {
        $value = $product->getAttributeByCodeAndLocale($code, $this->defaultLocale)?->getValue();

        return is_scalar($value) ? (string) $value : null;
    }

    private function setAttribute(ProductInterface $product, string $code, ?string $text): void
    {
        $attribute = $this->attribute($code);

        foreach ($this->localeCodes() as $locale) {
            $existing = $product->getAttributeByCodeAndLocale($code, $locale);
            // Sylius falls back to the default locale's value; only edit an exact match.
            if (null !== $existing && $existing->getLocaleCode() !== $locale) {
                $existing = null;
            }

            if (null === $text) {
                if (null !== $existing) {
                    $product->removeAttribute($existing);
                }
                continue;
            }

            if (null === $existing) {
                $existing = $this->attributeValueFactory->createNew();
                $existing->setAttribute($attribute);
                $existing->setLocaleCode($locale);
                $product->addAttribute($existing);
            }
            $existing->setValue($text);
        }
    }

    /** The product attribute for $code, created (as text) the first time it's needed. */
    private function attribute(string $code): ProductAttributeInterface
    {
        $attribute = $this->entityManager->getRepository(ProductAttribute::class)->findOneBy(['code' => $code]);
        if ($attribute instanceof ProductAttributeInterface) {
            return $attribute;
        }

        $attribute = $this->attributeFactory->createTyped('text');
        \assert($attribute instanceof ProductAttributeInterface);
        $attribute->setCode($code);
        $attribute->setTranslatable(true);
        foreach ($this->localeCodes() as $locale) {
            $attribute->setCurrentLocale($locale);
            $attribute->setName(self::ATTRIBUTES[$code]);
        }
        $attribute->setCurrentLocale($this->defaultLocale);
        $this->entityManager->persist($attribute);
        // Flushed now so the next row's lookup finds it instead of creating a duplicate.
        $this->entityManager->flush();

        return $attribute;
    }

    private function attachImage(ProductInterface $product, string $url, int $rowNumber, ImportReport $report): void
    {
        try {
            $file = $this->imageFetcher->fetch($url);
        } catch (\RuntimeException $e) {
            $report->add($rowNumber, $product->getCode(), ImportReport::WARNING, sprintf('image not added (%s)', $e->getMessage()));

            return;
        }

        try {
            $image = $this->imageFactory->createNew();
            $image->setType('main');
            $image->setFile($file);
            $product->addImage($image);
            $this->imageUploader->upload($image);
        } finally {
            if (is_file($file->getPathname())) {
                unlink($file->getPathname());
            }
        }
    }

    private function variantOf(ProductInterface $product, string $sku): ?ProductVariantInterface
    {
        $first = null;
        foreach ($product->getVariants() as $variant) {
            if (!$variant instanceof ProductVariantInterface) {
                continue;
            }
            if ($variant->getCode() === $sku) {
                return $variant;
            }
            $first ??= $variant;
        }

        return $first;
    }

    private function uniqueSlug(string $name, string $sku, string $locale): string
    {
        $base = (string) $this->slugger->slug($name)->lower();
        if ('' === $base) {
            $base = strtolower($sku);
        }

        $slug = $base;
        for ($i = 2; $this->slugTaken($slug, $locale); ++$i) {
            $slug = 2 === $i ? $base.'-'.strtolower($sku) : $base.'-'.strtolower($sku).'-'.$i;
        }

        $this->reservedSlugs[$locale][$slug] = true;

        return $slug;
    }

    private function slugTaken(string $slug, string $locale): bool
    {
        return isset($this->reservedSlugs[$locale][$slug])
            || null !== $this->entityManager->getRepository(ProductTranslation::class)->findOneBy(['slug' => $slug, 'locale' => $locale]);
    }

    /** @return non-empty-list<string> */
    private function localeCodes(): array
    {
        $codes = [$this->defaultLocale];
        foreach ($this->entityManager->getRepository(Locale::class)->findAll() as $locale) {
            $code = $locale->getCode();
            if (null !== $code && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /** @param array<string, string> $values */
    private static function skuOf(array $values): ?string
    {
        $sku = trim($values['sku'] ?? '');

        return '' === $sku ? null : $sku;
    }

    /** @param list<string> $fields */
    private static function fieldList(array $fields): string
    {
        $labels = [];
        foreach ($fields as $field) {
            $labels[str_starts_with($field, 'price:') ? 'price' : $field] = true;
        }

        return implode(', ', array_keys($labels));
    }
}
