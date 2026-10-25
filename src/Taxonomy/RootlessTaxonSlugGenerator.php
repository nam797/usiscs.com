<?php

declare(strict_types=1);

namespace App\Taxonomy;

use Sylius\Component\Taxonomy\Generator\TaxonSlugGeneratorInterface;
use Sylius\Component\Taxonomy\Model\TaxonInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\String\Slugger\SluggerInterface;
use Webmozart\Assert\Assert;

/**
 * Taxon slugs without the root taxon's slug in front: "safety-ppe" and
 * "safety-ppe/hand-protection" rather than Sylius's "products/safety-ppe/...".
 * Category pages are served from the site root (config/routes.yaml), so the
 * root ("Products", the menu's container) never shows in a URL.
 */
#[AsDecorator('sylius.generator.taxon_slug')]
final class RootlessTaxonSlugGenerator implements TaxonSlugGeneratorInterface
{
    public function __construct(private readonly SluggerInterface $slugger)
    {
    }

    public function generate(TaxonInterface $taxon, ?string $locale = null): string
    {
        $name = $taxon->getTranslation($locale)->getName();
        Assert::notEmpty($name, 'Cannot generate slug without a name.');

        return self::join($taxon->getParent(), $this->slugger->slug($name)->lower()->toString(), fn (TaxonInterface $parent): string => $parent->getTranslation($locale)->getSlug() ?: $this->generate($parent, $locale));
    }

    /**
     * @param callable(TaxonInterface): string $parentSlug
     *
     * @internal shared with the admin generator
     */
    public static function join(?TaxonInterface $parent, string $slug, callable $parentSlug): string
    {
        // Top-level taxons and the root's direct children (the departments) start the path.
        if (null === $parent || null === $parent->getParent()) {
            return $slug;
        }

        return $parentSlug($parent).'/'.$slug;
    }
}
