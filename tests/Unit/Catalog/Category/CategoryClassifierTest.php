<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Category;

use App\Catalog\Category\CategoryClassifier;
use App\Catalog\Category\CategoryTree;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CategoryClassifierTest extends TestCase
{
    /** @return iterable<string, array{string, string}> real ORS family names, including the ones a looser rule got wrong */
    public static function products(): iterable
    {
        yield 'welding gloves go to welding, not gloves' => ['Tillman® MIG Welding Gloves', 'welding_helmets_gloves_apparel'];
        yield 'welding helmet' => ['Jackson Safety Insight Digital Variable ADF Welding Helmets', 'welding_helmets_gloves_apparel'];
        yield 'welding respirator stays respiratory' => ['3M™ N95 Particulate Welding & Metal Pouring Respirators', 'respiratory_protection'];
        yield 'welding harness stays fall protection' => ['Honeywell Miller® H500 Welding Harnesses', 'fall_protection'];
        yield 'cut-resistant gloves' => ['HyFlex® 11-727 Cut Resistant Gloves', 'cut_resistant_gloves'];
        yield 'disposable gloves' => ['SHOWA® N-DEX® 9700 Series Disposable Nitrile Gloves', 'disposable_gloves'];
        yield 'chemical gloves' => ['SHOWA® Neoprene Elbow-Length Gauntlet Gloves', 'chemical_resistant_gloves'];
        yield 'leather gloves' => ['MCR Safety Pigskin Drivers Gloves', 'leather_work_gloves'];
        yield 'coated gloves by default' => ['HyFlex® 11-840 Nitrile Foam Palm Coated Gloves', 'coated_general_purpose_gloves'];
        yield 'brushless drill is a power tool, not a brush' => ['Milwaukee® Tool M18™ 1/2 in Brushless Hammer Drill/Driver Kits', 'power_tools_batteries'];
        yield 'Rayovac batteries are not a vac' => ['RAYOVAC® Ultra Pro™ Alkaline Batteries', 'power_tools_batteries'];
        yield 'shop vac filter is not a respirator cartridge' => ['Shop-Vac® Reusable Cartridge Filters', 'brooms_mops_floor_care'];
        yield 'degreaser is a cleaner, not grease' => ['CRC® Heavy Duty Degreasers', 'cleaners_soaps_disinfectants'];
        yield 'electrical tape' => ['Scotch® Super 33+™ Vinyl Electrical Tapes', 'electrical_tools_testers'];
        yield 'barricade tape' => ['Empire® Level Safety Barricade Tapes', 'signs_barricades_lockout'];
        yield 'tape measure' => ['Stanley® Tape Rules', 'measuring_layout'];
        yield 'duct tape' => ['3M™ DT11 Heavy-Duty Duct Tapes', 'tape'];
        yield 'pipe wrench' => ['Ridgid® Heavy-Duty Straight Pipe Wrenches', 'pipe_plumbing_tools'];
        yield 'Vise-Grip clamp is a clamp, not a vise' => ['Irwin® VISE-GRIP® Locking Chain Clamps', 'clamps_vises'];
        yield 'GearWrench screwdrivers are not wrenches' => ['GEARWRENCH® Modular Screwdriver Sets', 'screwdrivers_sockets_ratchets'];
        yield 'square nose pliers are not a square' => ["Klein Tools Ironworker's Side-Cutting Square Nose Pliers", 'pliers'];
        yield 'hammer handles' => ['TRUE TEMPER® Sledge Hammer Handles', 'hammers_bars_striking_tools'];
        yield 'painter\'s tape is tape' => ['ScotchBlue™ Multi-Surface Painter\'s Tapes', 'tape'];
        yield 'galvanized pail is not paint' => ['Magnolia Brush Hot-Dipped Galvanized Pails', 'storage_material_handling'];
        yield 'flap disc' => ['Weiler® Tiger® Big Cat® High Density Flap Discs', 'grinding_cutting_finishing'];
        yield 'wire brush is an abrasive' => ['Weiler® Knot Wire End Brushes', 'grinding_cutting_finishing'];
        yield 'floor brush' => ['Weiler® Perma-Sweep™ Floor Brushes', 'brooms_mops_floor_care'];
        yield 'electrolyte drink' => ['Medique® Medi-Lyte® Electrolyte Replenishers', 'heat_stress_hydration'];
        yield 'eyewash is first aid, not eye protection' => ['Honeywell Eyesaline Eyewash Wall Stations', 'first_aid_emergency'];
        yield 'insecticide is a chemical' => ['CRC® Wasp and Hornet Killer Plus™ Insecticides', 'adhesives_sealants_chemicals'];
    }

    #[DataProvider('products')]
    public function testItPlacesTheProduct(string $name, string $expected): void
    {
        self::assertSame($expected, CategoryClassifier::classify($name));
    }

    public function testUnknownProductsAreLeftForAHuman(): void
    {
        self::assertNull(CategoryClassifier::classify('Metals Babbits'));
    }

    public function testEveryRulePlacesProductsInALeafOfTheApprovedTree(): void
    {
        $flat = CategoryTree::flat();
        $rules = (new \ReflectionClassConstant(CategoryClassifier::class, 'RULES'))->getValue();
        $gloves = (new \ReflectionClassConstant(CategoryClassifier::class, 'GLOVES'))->getValue();
        self::assertIsArray($rules);
        self::assertIsArray($gloves);

        foreach ([...$rules, ...$gloves] as [$code]) {
            if ('hand_protection' === $code) {
                continue; // sorted on into the glove types
            }
            self::assertArrayHasKey($code, $flat);
            self::assertTrue($flat[$code]['leaf'], sprintf('%s has subcategories, so products must go into one of them.', $code));
        }
    }

    public function testTheTreeHasTheApprovedShape(): void
    {
        $flat = CategoryTree::flat();
        $count = static fn (int $depth): int => count(array_filter($flat, static fn (array $node): bool => $depth === $node['depth']));

        self::assertSame(7, $count(0));
        self::assertSame(31, $count(1));
        self::assertSame(14, $count(2));
        self::assertSame(['safety_ppe', 'hand_protection', 'cut_resistant_gloves'], CategoryTree::path('cut_resistant_gloves'));
    }
}
