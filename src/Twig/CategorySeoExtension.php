<?php

declare(strict_types=1);

namespace App\Twig;

use App\Seo\CategoryFacts;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Category pages' own title and meta description, so each category isn't
 * just the shop's name to search engines.
 *
 *  - current_taxon(): the category this page shows (null elsewhere).
 *  - category_meta_description(): the category's description when it has
 *    one (the per-category copy is still to be written); otherwise one
 *    built from what's in it: product count, top brands and subcategories.
 */
final class CategorySeoExtension extends AbstractExtension
{
    /** @param TaxonRepositoryInterface<TaxonInterface> $taxons */
    public function __construct(
        private readonly RequestStack $requests,
        #[Autowire(service: 'sylius.repository.taxon')]
        private readonly TaxonRepositoryInterface $taxons,
        private readonly LocaleContextInterface $localeContext,
        private readonly CategoryFacts $facts,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('current_taxon', $this->currentTaxon(...)),
            new TwigFunction('category_meta_description', $this->categoryMetaDescription(...)),
        ];
    }

    public function currentTaxon(): ?TaxonInterface
    {
        $slug = $this->requests->getMainRequest()?->attributes->get('slug');
        if (!is_string($slug) || '' === $slug) {
            return null;
        }

        $taxon = $this->taxons->findOneBySlug($slug, $this->localeContext->getLocaleCode());

        return $taxon instanceof TaxonInterface ? $taxon : null;
    }

    public function categoryMetaDescription(TaxonInterface $taxon, ChannelInterface $channel): string
    {
        $own = SeoExtension::plain($taxon->getDescription());
        if ('' !== $own) {
            return SeoExtension::shorten($own);
        }

        $count = $this->facts->productCount($taxon, $channel);
        $brands = $this->facts->topBrands($taxon, $channel, $this->localeContext->getLocaleCode());
        $children = [];
        foreach ($taxon->getEnabledChildren() as $child) {
            $children[] = (string) $child->getName();
        }

        $what = $taxon->isRoot() ? 'Industrial and construction supplies' : (string) $taxon->getName();
        $sentences = [sprintf('%s: %s product%s%s', $what, number_format($count), 1 === $count ? '' : 's', [] === $brands ? '' : ' from brands like '.self::list($brands, false))];
        if ([] !== $children) {
            $sentences[] = 'Including '.self::list(array_slice($children, 0, 3), \count($children) > 3);
        }
        $sentences[] = 'Order online or request bulk pricing from US Industrial Supply Chain Solutions';

        return SeoExtension::shorten(implode('. ', $sentences).'.');
    }

    /**
     * "A, B and C", or "A, B, C and more".
     *
     * @param list<string> $items
     */
    private static function list(array $items, bool $andMore): string
    {
        if ($andMore) {
            return implode(', ', $items).' and more';
        }
        $last = array_pop($items);

        return [] === $items ? (string) $last : implode(', ', $items).' and '.$last;
    }
}
