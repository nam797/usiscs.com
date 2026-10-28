<?php

declare(strict_types=1);

namespace App\Twig;

use App\Catalog\Import\ProductImporter;
use App\Entity\Product\ProductVariant;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Search-engine tags the shop layout and product pages need.
 *
 *  - seo_canonical_url(): this page's address without anything that only
 *    re-arranges it (sorting, ?limit=, a search), so each category is one
 *    page to Google. Pagination (?page=2) is kept: each page of a category
 *    is its own page. RobotsTagListener marks the re-arranged versions
 *    noindex to match.
 *  - product_meta_description(): the product's own meta description, short
 *    description or description; failing those, one built from its brand,
 *    part number, name, pack unit and price. Never the site-wide default,
 *    which would make every product page look the same.
 */
final class SeoExtension extends AbstractExtension
{
    /** Google shows about this many characters of a description. */
    public const DESCRIPTION_LENGTH = 155;

    public function __construct(private readonly RequestStack $requests)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('seo_canonical_url', $this->canonicalUrl(...)),
            new TwigFunction('product_meta_description', $this->productMetaDescription(...)),
        ];
    }

    public function canonicalUrl(): string
    {
        $request = $this->requests->getMainRequest();
        if (null === $request) {
            return '';
        }

        $page = $request->query->getInt('page');

        return $request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo().($page > 1 ? '?page='.$page : '');
    }

    public function productMetaDescription(ProductInterface $product, ?ChannelInterface $channel = null): string
    {
        foreach ([$product->getMetaDescription(), $product->getShortDescription(), $product->getDescription()] as $text) {
            $text = self::plain($text);
            if ('' !== $text) {
                return self::shorten($text);
            }
        }

        $brand = self::attribute($product, 'manufacturer');
        $partNumber = self::attribute($product, 'part_number');
        $name = (string) $product->getName();
        $variant = $product->getEnabledVariants()->first() ?: $product->getVariants()->first();

        $what = trim(implode(' ', array_filter([
            null !== $brand && !str_contains(mb_strtolower($name), mb_strtolower($brand)) ? $brand : null,
            $name,
            null === $partNumber ? null : '('.$partNumber.')',
        ])));
        $sentences = [$what];

        $price = null !== $channel && $variant instanceof ProductVariant ? $variant->getChannelPricingForChannel($channel)?->getPrice() : null;
        $packUnit = $variant instanceof ProductVariant ? $variant->getPackUnit() : null;
        if (null !== $price) {
            $sentences[] = sprintf('$%s %s', number_format($price / 100, 2), null === $packUnit ? 'each' : $packUnit->perPrice());
        } elseif (null !== $packUnit) {
            $sentences[] = 'Sold '.$packUnit->perPrice();
        }
        $sentences[] = 'Order online or request bulk pricing from US Industrial Supply Chain Solutions';

        return self::shorten(implode('. ', $sentences).'.');
    }

    private static function attribute(ProductInterface $product, string $code): ?string
    {
        if (!isset(ProductImporter::ATTRIBUTES[$code])) {
            return null;
        }
        $value = $product->getAttributeByCodeAndLocale($code)?->getValue();

        return is_scalar($value) && '' !== trim((string) $value) ? trim((string) $value) : null;
    }

    private static function plain(?string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $html), \ENT_QUOTES | \ENT_HTML5)));
    }

    /** At most DESCRIPTION_LENGTH characters, cut at a word, with "…" when cut. */
    private static function shorten(string $text): string
    {
        if (mb_strlen($text) <= self::DESCRIPTION_LENGTH) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::DESCRIPTION_LENGTH - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim(false === $space ? $cut : mb_substr($cut, 0, $space), ' ,.;:-').'…';
    }
}
