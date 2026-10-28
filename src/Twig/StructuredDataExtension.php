<?php

declare(strict_types=1);

namespace App\Twig;

use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Inventory\Checker\AvailabilityCheckerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * schema.org structured data (JSON-LD) for search engines:
 *
 *  - ld_product(): Product with its Offer (price, currency, availability),
 *    or an AggregateOffer for a product sold in several sizes/colours;
 *    brand and part number from the import's attributes.
 *  - ld_breadcrumbs(): BreadcrumbList matching the page's breadcrumbs.
 *  - ld_organization() / ld_website(): who runs the site, on the homepage.
 *  - json_ld(): one <script type="application/ld+json"> tag, with "<", ">"
 *    and "&" escaped so text from the catalog can't close the tag.
 *
 * No AggregateRating: there are no reviews.
 */
final class StructuredDataExtension extends AbstractExtension
{
    private const CONTEXT = 'https://schema.org';

    /** As on the contact page (templates/shop/contact/index.html.twig). */
    public const TELEPHONE = '+1-912-665-7257';

    private const IMAGE_FILTER = 'sylius_shop_product_large_thumbnail';

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly AvailabilityCheckerInterface $availability,
        private readonly ?CacheManager $images = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('json_ld', $this->jsonLd(...), ['is_safe' => ['html']]),
            new TwigFunction('ld_product', $this->product(...)),
            new TwigFunction('ld_breadcrumbs', $this->breadcrumbs(...)),
            new TwigFunction('ld_organization', $this->organization(...)),
            new TwigFunction('ld_website', $this->website(...)),
        ];
    }

    /** @param array<string, mixed> ...$items */
    public function jsonLd(array ...$items): string
    {
        $html = '';
        foreach ($items as $item) {
            $html .= '<script type="application/ld+json">'.json_encode(
                $item,
                \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
            )."</script>\n";
        }

        return $html;
    }

    /** @return array<string, mixed> */
    public function product(ProductInterface $product, ChannelInterface $channel): array
    {
        $url = $this->urls->generate('sylius_shop_product_show', ['slug' => $product->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL);
        $variants = [];
        foreach ($product->getEnabledVariants() as $variant) {
            if ($variant instanceof ProductVariantInterface && null !== $variant->getChannelPricingForChannel($channel)?->getPrice()) {
                $variants[] = $variant;
            }
        }

        $data = ['@context' => self::CONTEXT, '@type' => 'Product', 'name' => (string) $product->getName(), 'url' => $url];

        $description = SeoExtension::plain($product->getDescription() ?? $product->getShortDescription());
        if ('' !== $description) {
            $data['description'] = $description;
        }
        $data['sku'] = 1 === \count($variants) ? (string) $variants[0]->getCode() : (string) $product->getCode();
        if (null !== $partNumber = self::attribute($product, 'part_number')) {
            $data['mpn'] = $partNumber;
        }
        if (null !== $brand = self::attribute($product, 'manufacturer')) {
            $data['brand'] = ['@type' => 'Brand', 'name' => $brand];
        }
        $images = [];
        foreach ($product->getImages() as $image) {
            if (null !== $this->images && null !== $image->getPath()) {
                $images[] = $this->images->getBrowserPath($image->getPath(), self::IMAGE_FILTER);
            }
        }
        if ([] !== $images) {
            $data['image'] = $images;
        }

        if ([] === $variants) {
            return $data;
        }

        $currency = (string) $channel->getBaseCurrency()?->getCode();
        $seller = ['@type' => 'Organization', 'name' => (string) $channel->getName()];
        $prices = array_map(static fn (ProductVariantInterface $v): int => (int) $v->getChannelPricingForChannel($channel)?->getPrice(), $variants);
        $inStock = [] !== array_filter($variants, fn (ProductVariantInterface $v): bool => $this->availability->isStockAvailable($v));

        $data['offers'] = 1 === \count($variants)
            ? [
                '@type' => 'Offer',
                'url' => $url,
                'price' => self::money($prices[0]),
                'priceCurrency' => $currency,
                'availability' => self::CONTEXT.($inStock ? '/InStock' : '/OutOfStock'),
                'itemCondition' => self::CONTEXT.'/NewCondition',
                'seller' => $seller,
            ]
            : [
                '@type' => 'AggregateOffer',
                'url' => $url,
                'lowPrice' => self::money(min($prices)),
                'highPrice' => self::money(max($prices)),
                'offerCount' => \count($variants),
                'priceCurrency' => $currency,
                'availability' => self::CONTEXT.($inStock ? '/InStock' : '/OutOfStock'),
                'itemCondition' => self::CONTEXT.'/NewCondition',
                'seller' => $seller,
            ];

        return $data;
    }

    /**
     * Home, then each category down to $taxon, then the product when given:
     * the same trail as the visible breadcrumbs.
     *
     * @return array<string, mixed>
     */
    public function breadcrumbs(TaxonInterface $taxon, ?ProductInterface $product = null): array
    {
        $trail = [['Home', $this->urls->generate('sylius_shop_homepage', [], UrlGeneratorInterface::ABSOLUTE_URL)]];
        foreach ([...array_reverse($taxon->getAncestors()->toArray()), $taxon] as $category) {
            if ($category->isEnabled()) {
                $trail[] = [(string) $category->getName(), $this->urls->generate('sylius_shop_product_index', ['slug' => $category->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL)];
            }
        }
        if (null !== $product) {
            $trail[] = [(string) $product->getName(), $this->urls->generate('sylius_shop_product_show', ['slug' => $product->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL)];
        }

        $items = [];
        foreach ($trail as $position => [$name, $url]) {
            $items[] = ['@type' => 'ListItem', 'position' => $position + 1, 'name' => $name, 'item' => $url];
        }

        return ['@context' => self::CONTEXT, '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    /** @return array<string, mixed> */
    public function organization(ChannelInterface $channel, string $logoUrl): array
    {
        $home = $this->urls->generate('sylius_shop_homepage', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $contact = ['@type' => 'ContactPoint', 'telephone' => self::TELEPHONE, 'contactType' => 'sales', 'areaServed' => 'US', 'availableLanguage' => 'English'];
        if (null !== $channel->getContactEmail()) {
            $contact['email'] = $channel->getContactEmail();
        }

        return [
            '@context' => self::CONTEXT,
            '@type' => 'Organization',
            'name' => (string) $channel->getName(),
            'url' => $home,
            'logo' => $logoUrl,
            'telephone' => self::TELEPHONE,
            'contactPoint' => $contact,
        ];
    }

    /** @return array<string, mixed> */
    public function website(ChannelInterface $channel): array
    {
        return [
            '@context' => self::CONTEXT,
            '@type' => 'WebSite',
            'name' => (string) $channel->getName(),
            'url' => $this->urls->generate('sylius_shop_homepage', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }

    private static function attribute(ProductInterface $product, string $code): ?string
    {
        $value = $product->getAttributeByCodeAndLocale($code)?->getValue();

        return is_scalar($value) && '' !== trim((string) $value) ? trim((string) $value) : null;
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
