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
 *  - ld_product(): a one-size product is a Product with its Offer (price,
 *    currency, availability), SKU = the item number and MPN = the
 *    manufacturer part number. A product sold in several sizes/colours is
 *    a ProductGroup with one Product per size (its own item number, price
 *    and availability), as Google asks for variants. Brand from the
 *    import's attribute, shown in normal case when ORS sent it in capitals.
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

        $name = (string) $product->getName();
        $data = ['@context' => self::CONTEXT, '@type' => 1 < \count($variants) ? 'ProductGroup' : 'Product', 'name' => $name, 'url' => $url];

        $description = SeoExtension::plain($product->getDescription() ?? $product->getShortDescription());
        if ('' !== $description) {
            $data['description'] = $description;
        }
        if (null !== $brand = self::attribute($product, 'manufacturer')) {
            $data['brand'] = ['@type' => 'Brand', 'name' => self::brandName($brand)];
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
            $data['sku'] = (string) $product->getCode();

            return $data;
        }

        if (1 === \count($variants)) {
            $data['sku'] = (string) $variants[0]->getCode();
            // The part number is stored per product, from the import's first
            // row: only certain for a product with one item.
            if (null !== $partNumber = self::attribute($product, 'part_number')) {
                $data['mpn'] = $partNumber;
            }
            $data['offers'] = $this->offer($variants[0], $channel, $url);

            return $data;
        }

        $data['productGroupID'] = (string) $product->getCode();
        $data['hasVariant'] = [];
        foreach ($variants as $variant) {
            $variantName = trim((string) $variant->getName());
            $item = [
                '@type' => 'Product',
                'name' => '' === $variantName ? $name : $name.', '.$variantName,
                'sku' => (string) $variant->getCode(),
                'url' => $url,
            ];
            if ([] !== $images) {
                $item['image'] = $images;
            }
            $item['offers'] = $this->offer($variant, $channel, $url);
            $data['hasVariant'][] = $item;
        }

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

    /** @return array<string, mixed> */
    private function offer(ProductVariantInterface $variant, ChannelInterface $channel, string $url): array
    {
        return [
            '@type' => 'Offer',
            'url' => $url,
            'price' => self::money((int) $variant->getChannelPricingForChannel($channel)?->getPrice()),
            'priceCurrency' => (string) $channel->getBaseCurrency()?->getCode(),
            'availability' => self::CONTEXT.($this->availability->isStockAvailable($variant) ? '/InStock' : '/OutOfStock'),
            'itemCondition' => self::CONTEXT.'/NewCondition',
            'seller' => ['@type' => 'Organization', 'name' => (string) $channel->getName()],
        ];
    }

    /**
     * "JACKSON PROFESSIONAL TOOLS" -> "Jackson Professional Tools": ORS
     * sends some brands in capitals. Mixed-case names are left alone, and so
     * are words of up to 3 letters ("MCR Safety", "3M", "MSA").
     */
    public static function brandName(string $brand): string
    {
        if ($brand !== mb_strtoupper($brand)) {
            return $brand;
        }

        return implode(' ', array_map(
            static fn (string $word): string => mb_strlen($word) <= 3 ? $word : mb_convert_case($word, \MB_CASE_TITLE),
            explode(' ', $brand),
        ));
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
