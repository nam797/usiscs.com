<?php

declare(strict_types=1);

namespace App\Catalog\Import;

use App\Catalog\GoLive\ReadinessChecker;
use App\Catalog\Unit\PackUnit;
use App\Entity\Catalog\ProductImportState;
use App\Entity\Channel\Channel;
use App\Entity\Locale\Locale;
use App\Entity\Product\Product;
use App\Entity\Product\ProductAttribute;
use App\Entity\Product\ProductTranslation;
use App\Entity\Product\ProductVariant;
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
use Sylius\Component\Product\Factory\ProductVariantFactoryInterface;
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
 * - Rows with a "group" become variants of one product (sizes, colours),
 *   each still its own SKU; the product's own fields come from the first
 *   row of the group.
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
    ];

    /** @var array<string, array<string, true>> slugs handed out during this run, per locale */
    private array $reservedSlugs = [];

    /** @var array<string, array<string, scalar|null>> product-field snapshot of each group this run has merged */
    private array $mergedGroups = [];

    private readonly AsciiSlugger $slugger;

    /**
     * @param FactoryInterface<ProductTaxonInterface>          $productTaxonFactory
     * @param FactoryInterface<ChannelPricingInterface>        $channelPricingFactory
     * @param AttributeFactoryInterface<ProductAttributeInterface> $attributeFactory
     * @param FactoryInterface<ProductAttributeValueInterface> $attributeValueFactory
     * @param FactoryInterface<ProductImageInterface>          $imageFactory
     * @param ProductFactoryInterface<ProductInterface>        $productFactory
     * @param ProductVariantFactoryInterface<ProductVariantInterface> $variantFactory
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'sylius.factory.product')]
        private readonly ProductFactoryInterface $productFactory,
        #[Autowire(service: 'sylius.factory.product_variant')]
        private readonly ProductVariantFactoryInterface $variantFactory,
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
        $this->mergedGroups = [];
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
                    $report->add($rowNumber, $row->supplierSku, ImportReport::ERROR, $row->sku === $row->supplierSku
                        ? sprintf('duplicate SKU, already on row %d; this row was skipped', $seen[$row->sku])
                        : sprintf('becomes product code "%s", already used on row %d; this row was skipped', $row->sku, $seen[$row->sku]));
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

        $product = $this->entityManager->getRepository(Product::class)->findOneBy(['code' => $row->group ?? $row->sku]);
        $state = $this->entityManager->getRepository(ProductImportState::class)->findOneBy(['productCode' => $row->sku]);

        // A SKU is one variant, in exactly one product.
        $owner = $this->entityManager->getRepository(ProductVariant::class)->findOneBy(['code' => $row->sku]);
        $ownerCode = $owner instanceof ProductVariantInterface ? $owner->getProduct()?->getCode() : null;
        if (null !== $ownerCode && $ownerCode !== ($row->group ?? $row->sku)) {
            ++$report->failed;
            $report->add($rowNumber, $row->sku, ImportReport::ERROR, null === $row->group
                ? sprintf('is a variant of product "%s"; put "%s" in its group column', $ownerCode, $ownerCode)
                : sprintf('is already product "%s"; delete that product in the admin before grouping this SKU under "%s"', $ownerCode, $row->group));

            return;
        }

        if (!$product instanceof ProductInterface) {
            $this->create($row, $rowNumber, $sourceName, $state, $dryRun, $report);

            return;
        }

        if (null !== $row->group) {
            $this->updateGroup($product, $row, $rowNumber, $sourceName, $state, $report);

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
        $product->setCode($row->group ?? $row->sku);
        $product->setEnabled(false);
        $product->setVariantSelectionMethod(ProductInterface::VARIANT_SELECTION_CHOICE);
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
            $this->entityManager->persist(new ProductImportState($row->sku, $sourceName, $incoming, $row->supplierSku));
        } else {
            // The product was imported before and then deleted in the admin.
            $state->recordImport($sourceName, $incoming);
            $state->setSupplierSku($row->supplierSku);
        }

        if (null !== $row->group) {
            // Later rows of the group look the product and this state up.
            $this->entityManager->flush();
            $this->mergedGroups[$row->group] = $this->productFields($row);
        }

        ++$report->created;
        if ($row->sku !== $row->supplierSku) {
            $report->add($rowNumber, $row->supplierSku, ImportReport::INFO, sprintf('saved as product code "%s" (Sylius codes allow only letters, numbers, - and _)', $row->sku));
        }

        if (null !== $row->group) {
            $report->add($rowNumber, $row->sku, ImportReport::INFO, sprintf('created product "%s" with this SKU as its first variant', $row->group));
        }

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

    /** A SKU in a group whose product already exists: add or update its variant. */
    private function updateGroup(ProductInterface $product, ProductRow $row, int $rowNumber, string $sourceName, ?ProductImportState $state, ImportReport $report): void
    {
        $group = (string) $row->group;
        $groupState = $state ?? $this->stateOfAnyVariant($product);
        if (null === $groupState) {
            ++$report->untouched;
            $report->add($rowNumber, $row->sku, ImportReport::INFO, sprintf('product "%s" was not created by an import; left as it is', $group));

            return;
        }

        $channelCodes = [];
        foreach ($product->getChannels() as $channel) {
            $channelCodes[] = (string) $channel->getCode();
        }

        $variant = $this->variantOf($product, $row->sku, false);
        $productFields = $this->productFields($row);
        $productSnapshot = array_intersect_key($groupState->getImportedValues(), $productFields);
        $changed = [];
        $kept = [];

        // The product's own fields: once per group per run, from its first row.
        if (isset($this->mergedGroups[$group])) {
            $productSnapshot = $this->mergedGroups[$group];
        } else {
            $anyVariant = $variant ?? $this->variantOf($product, '');
            \assert(null !== $anyVariant);
            $result = $this->merger->merge($productSnapshot, $this->current($product, $anyVariant, array_keys($productFields)), $productFields);
            $this->write($product, $anyVariant, $result->toWrite);
            $productSnapshot = $this->mergedGroups[$group] = $result->snapshot;
            $changed = array_keys($result->toWrite);
            $kept = $result->kept;
        }

        if (null === $variant) {
            $variant = $this->variantFactory->createForProduct($product);
            $variant->setCode($row->sku);
            $variant->setTracked(false);
            $variant->setCurrentLocale($this->defaultLocale);
            $variant->setFallbackLocale($this->defaultLocale);
            $variantFields = $this->variantFields($row, $channelCodes);
            $this->write($product, $variant, $variantFields);
            $product->addVariant($variant);
            $this->entityManager->persist($variant);

            $snapshot = $productSnapshot + $variantFields;
            if (null === $state) {
                $this->entityManager->persist(new ProductImportState($row->sku, $sourceName, $snapshot, $row->supplierSku));
            } else {
                $state->recordImport($sourceName, $snapshot);
                $state->setSupplierSku($row->supplierSku);
            }

            ++$report->created;
            $report->add($rowNumber, $row->sku, ImportReport::INFO, sprintf('added to product "%s"%s', $group, $product->isEnabled() ? ' (live)' : ''));

            return;
        }

        \assert(null !== $state);
        $variantFields = $this->variantFields($row, $channelCodes);
        $variantSnapshot = array_diff_key($state->getImportedValues(), $productFields);
        $result = $this->merger->merge($variantSnapshot, $this->current($product, $variant, array_keys($variantFields)), $variantFields);
        $this->write($product, $variant, $result->toWrite);
        $state->recordImport($sourceName, $productSnapshot + $result->snapshot);
        $state->setSupplierSku($row->supplierSku);

        $changed = [...$changed, ...array_keys($result->toWrite)];
        $kept = [...$kept, ...$result->kept];
        if ([] === $changed) {
            ++$report->unchanged;
        } else {
            ++$report->updated;
            $report->add($rowNumber, $row->sku, ImportReport::INFO, sprintf('updated %s', self::fieldList($changed)));
        }
        if ([] !== $kept) {
            $report->add($rowNumber, $row->sku, ImportReport::INFO, sprintf('kept admin edits to %s', self::fieldList($kept)));
        }
    }

    private function stateOfAnyVariant(ProductInterface $product): ?ProductImportState
    {
        $codes = [];
        foreach ($product->getVariants() as $variant) {
            $codes[] = (string) $variant->getCode();
        }

        return [] === $codes ? null : $this->entityManager->getRepository(ProductImportState::class)->findOneBy(['productCode' => $codes]);
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
        $state->setSupplierSku($row->supplierSku);

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
        return $this->productFields($row) + $this->variantFields($row, $channelCodes);
    }

    /**
     * Fields that belong to the product, shared by every SKU in a group.
     *
     * @return array<string, scalar|null>
     */
    private function productFields(ProductRow $row): array
    {
        $values = ['name' => $row->name, 'category' => $row->categoryCode];

        $optional = [
            'description' => $row->description,
            'short_description' => $row->shortDescription,
            'manufacturer' => $row->manufacturer,
        ];
        // A part number is per SKU, so a group's product doesn't get one.
        if (null === $row->group) {
            $optional['part_number'] = $row->partNumber;
        }
        foreach ($optional as $column => $value) {
            if ($row->provides($column)) {
                $values[$column] = $value;
            }
        }

        return $values;
    }

    /**
     * Fields of the SKU itself.
     *
     * @param list<string> $channelCodes
     *
     * @return array<string, scalar|null>
     */
    private function variantFields(ProductRow $row, array $channelCodes): array
    {
        $values = [];
        if (null !== $row->group) {
            $values['variant_name'] = $row->variantName;
        }
        if ($row->provides('unit_of_measure')) {
            $values['unit_of_measure'] = $row->unitOfMeasure?->toString();
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
                'variant_name' === $field => $variant->getTranslation($this->defaultLocale)->getName(),
                'unit_of_measure' === $field => $variant instanceof ProductVariant ? $variant->getPackUnit()?->toString() : null,
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
                'variant_name' === $field => $this->setVariantName($variant, $text),
                'unit_of_measure' === $field => $variant instanceof ProductVariant ? $variant->setPackUnit(null === $text ? null : PackUnit::parse($text)) : null,
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

    private function setVariantName(ProductVariantInterface $variant, ?string $name): void
    {
        foreach ($this->localeCodes() as $locale) {
            $variant->getTranslation($locale)->setName($name);
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

    /** The variant with this code; otherwise (when $orFirst) the product's first variant. */
    private function variantOf(ProductInterface $product, string $sku, bool $orFirst = true): ?ProductVariantInterface
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

        return $orFirst ? $first : null;
    }

    private function uniqueSlug(string $name, string $sku, string $locale): string
    {
        // Trademark signs would otherwise become "-r-" / "tm" in the URL.
        $base = (string) $this->slugger->slug(str_replace(['®', '™', '©', '℠'], '', $name))->lower();
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
