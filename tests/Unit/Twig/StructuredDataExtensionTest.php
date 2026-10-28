<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Entity\Channel\Channel;
use App\Entity\Channel\ChannelPricing;
use App\Entity\Currency\Currency;
use App\Entity\Product\Product;
use App\Entity\Product\ProductVariant;
use App\Entity\Taxonomy\Taxon;
use App\Twig\StructuredDataExtension;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Inventory\Checker\AvailabilityCheckerInterface;
use Sylius\Component\Inventory\Model\StockableInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

final class StructuredDataExtensionTest extends TestCase
{
    public function testAProductWithOnePriceHasAnOffer(): void
    {
        $data = $this->extension(inStock: true)->product($this->product([1740]), $this->channel());

        self::assertSame('Product', $data['@type']);
        self::assertSame('Respirator Cleaning Pads', $data['name']);
        self::assertSame('https://usiscs.com/products/respirator-cleaning-pads', $data['url']);
        self::assertSame('PADS-0', $data['sku']);
        self::assertSame([
            '@type' => 'Offer',
            'url' => 'https://usiscs.com/products/respirator-cleaning-pads',
            'price' => '17.40',
            'priceCurrency' => 'USD',
            'availability' => 'https://schema.org/InStock',
            'itemCondition' => 'https://schema.org/NewCondition',
            'seller' => ['@type' => 'Organization', 'name' => 'US Industrial Supply Chain Solutions'],
        ], $data['offers']);
        self::assertArrayNotHasKey('aggregateRating', $data);
    }

    public function testAProductInSeveralSizesHasAPriceRange(): void
    {
        $data = $this->extension(inStock: false)->product($this->product([1200, 1500, 1350]), $this->channel());

        self::assertSame('PADS', $data['sku']);
        $offers = $data['offers'];
        self::assertIsArray($offers);
        self::assertSame('AggregateOffer', $offers['@type']);
        self::assertSame(['12.00', '15.00', 3], [$offers['lowPrice'], $offers['highPrice'], $offers['offerCount']]);
        self::assertSame('https://schema.org/OutOfStock', $offers['availability']);
    }

    public function testBreadcrumbsFollowTheCategoryTrail(): void
    {
        $root = $this->taxon('Products', 'products');
        $category = $this->taxon('Safety & PPE', 'safety-ppe');
        $root->addChild($category);

        $data = $this->extension()->breadcrumbs($category, $this->product([1740]));

        self::assertSame(
            [
                [1, 'Home', 'https://usiscs.com/'],
                [2, 'Products', 'https://usiscs.com/products'],
                [3, 'Safety & PPE', 'https://usiscs.com/safety-ppe'],
                [4, 'Respirator Cleaning Pads', 'https://usiscs.com/products/respirator-cleaning-pads'],
            ],
            array_map(static fn (mixed $item): array => is_array($item) ? [$item['position'], $item['name'], $item['item']] : [], is_array($data['itemListElement']) ? $data['itemListElement'] : []),
        );
    }

    public function testTheScriptTagCannotBeClosedByCatalogText(): void
    {
        $html = $this->extension()->jsonLd(['name' => 'Pads </script><script>alert(1)</script> & more']);

        self::assertStringStartsWith('<script type="application/ld+json">', $html);
        self::assertSame(1, substr_count($html, '</script>'), 'only the tag\'s own closing tag');
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringNotContainsString('& more', $html);
        $json = substr($html, strlen('<script type="application/ld+json">'), -strlen("</script>\n"));
        self::assertSame(['name' => 'Pads </script><script>alert(1)</script> & more'], json_decode($json, true), 'still the same text to a JSON reader');
    }

    /** @param list<int> $prices */
    private function product(array $prices): Product
    {
        $product = new Product();
        $product->setCurrentLocale('en_US');
        $product->setFallbackLocale('en_US');
        $product->setCode('PADS');
        $product->setName('Respirator Cleaning Pads');
        $product->setSlug('respirator-cleaning-pads');
        foreach ($prices as $i => $price) {
            $variant = new ProductVariant();
            $variant->setCode('PADS-'.$i);
            $pricing = new ChannelPricing();
            $pricing->setChannelCode('default');
            $pricing->setPrice($price);
            $variant->addChannelPricing($pricing);
            $product->addVariant($variant);
        }

        return $product;
    }

    private function taxon(string $name, string $slug): Taxon
    {
        $taxon = new Taxon();
        $taxon->setCurrentLocale('en_US');
        $taxon->setFallbackLocale('en_US');
        $taxon->setName($name);
        $taxon->setSlug($slug);

        return $taxon;
    }

    private function channel(): Channel
    {
        $currency = new Currency();
        $currency->setCode('USD');
        $channel = new Channel();
        $channel->setCode('default');
        $channel->setName('US Industrial Supply Chain Solutions');
        $channel->setBaseCurrency($currency);

        return $channel;
    }

    private function extension(bool $inStock = true): StructuredDataExtension
    {
        $urls = new class implements UrlGeneratorInterface {
            /** @param array<string, mixed> $parameters */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                $slug = is_string($parameters['slug'] ?? null) ? $parameters['slug'] : '';

                return 'https://usiscs.com'.match ($name) {
                    'sylius_shop_homepage' => '/',
                    'sylius_shop_product_show' => '/products/'.$slug,
                    'sylius_shop_product_index' => '/'.$slug,
                    default => '/'.$name,
                };
            }

            public function setContext(RequestContext $context): void
            {
            }

            public function getContext(): RequestContext
            {
                return new RequestContext();
            }
        };
        $availability = new class($inStock) implements AvailabilityCheckerInterface {
            public function __construct(private readonly bool $inStock)
            {
            }

            public function isStockAvailable(StockableInterface $stockable): bool
            {
                return $this->inStock;
            }

            public function isStockSufficient(StockableInterface $stockable, int $quantity): bool
            {
                return $this->inStock;
            }
        };

        return new StructuredDataExtension($urls, $availability);
    }
}
