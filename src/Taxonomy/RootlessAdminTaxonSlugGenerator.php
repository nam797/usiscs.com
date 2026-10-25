<?php

declare(strict_types=1);

namespace App\Taxonomy;

use Sylius\Bundle\AdminBundle\Generator\TaxonSlugGeneratorInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Taxonomy\Generator\TaxonSlugGeneratorInterface as BaseTaxonSlugGeneratorInterface;
use Sylius\Component\Taxonomy\Model\TaxonInterface as BaseTaxonInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * The slug the admin's taxon form suggests, without the root taxon's slug:
 * see RootlessTaxonSlugGenerator.
 */
#[AsDecorator('sylius_admin.generator.taxon_slug')]
final class RootlessAdminTaxonSlugGenerator implements TaxonSlugGeneratorInterface
{
    public function __construct(
        private readonly BaseTaxonSlugGeneratorInterface $taxonSlugGenerator,
        private readonly SluggerInterface $slugger,
    ) {
    }

    public function generate(string $name, string $localeCode, ?TaxonInterface $parent = null): string
    {
        return RootlessTaxonSlugGenerator::join($parent, $this->slugger->slug($name)->lower()->toString(), fn (BaseTaxonInterface $parent): string => $this->taxonSlugGenerator->generate($parent, $localeCode));
    }
}
