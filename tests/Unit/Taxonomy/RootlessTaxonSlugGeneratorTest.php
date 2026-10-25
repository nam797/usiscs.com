<?php

declare(strict_types=1);

namespace App\Tests\Unit\Taxonomy;

use App\Entity\Taxonomy\Taxon;
use App\Taxonomy\RootlessAdminTaxonSlugGenerator;
use App\Taxonomy\RootlessTaxonSlugGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class RootlessTaxonSlugGeneratorTest extends TestCase
{
    public function testARootTaxonUsesItsOwnName(): void
    {
        self::assertSame('products', $this->generator()->generate($this->taxon('Products')));
    }

    public function testADepartmentLeavesTheRootOutOfItsSlug(): void
    {
        $department = $this->taxon('Safety & PPE', parent: $this->taxon('Products', slug: 'products'));

        self::assertSame('safety-ppe', $this->generator()->generate($department, 'en_US'));
    }

    public function testDeeperCategoriesBuildOnTheirParentsSlug(): void
    {
        $department = $this->taxon('Safety & PPE', slug: 'safety-ppe', parent: $this->taxon('Products', slug: 'products'));
        $category = $this->taxon('Hand Protection', parent: $department);
        $subcategory = $this->taxon('Cut-Resistant Gloves', parent: $category);

        self::assertSame('safety-ppe/hand-protection', $this->generator()->generate($category, 'en_US'));
        self::assertSame('safety-ppe/hand-protection/cut-resistant-gloves', $this->generator()->generate($subcategory, 'en_US'));
    }

    public function testAParentWithoutASlugYetGetsOneGenerated(): void
    {
        $department = $this->taxon('Safety & PPE', parent: $this->taxon('Products', slug: 'products'));

        self::assertSame('safety-ppe/footwear', $this->generator()->generate($this->taxon('Footwear', parent: $department), 'en_US'));
    }

    public function testTheAdminFormSuggestsTheSameSlugs(): void
    {
        $root = $this->taxon('Products', slug: 'products');
        $department = $this->taxon('Safety & PPE', slug: 'safety-ppe', parent: $root);
        $admin = new RootlessAdminTaxonSlugGenerator($this->generator(), new AsciiSlugger());

        self::assertSame('tools', $admin->generate('Tools', 'en_US'));
        self::assertSame('tools', $admin->generate('Tools', 'en_US', $root));
        self::assertSame('safety-ppe/hand-protection', $admin->generate('Hand Protection', 'en_US', $department));
    }

    private function generator(): RootlessTaxonSlugGenerator
    {
        return new RootlessTaxonSlugGenerator(new AsciiSlugger());
    }

    private function taxon(string $name, ?string $slug = null, ?Taxon $parent = null): Taxon
    {
        $taxon = new Taxon();
        $taxon->setCurrentLocale('en_US');
        $taxon->setFallbackLocale('en_US');
        $taxon->setName($name);
        $taxon->setSlug($slug);
        $parent?->addChild($taxon);

        return $taxon;
    }
}
