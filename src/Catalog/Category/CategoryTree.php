<?php

declare(strict_types=1);

namespace App\Catalog\Category;

/**
 * The category tree the client approved on 2026-11-20 (the "USISCS
 * Category Tree Draft" of 2026-10-24): 7 departments, 31 categories, and a
 * third level under Hand Protection and Hand Tools.
 *
 * Each entry: code => [name, product count in the draft (ORS SKUs), children].
 * Departments and categories are in the draft's order (largest first).
 */
final class CategoryTree
{
    public const TREE = [
        'safety_ppe' => ['Safety & PPE', 993, [
            'hand_protection' => ['Hand Protection', 320, [
                'coated_general_purpose_gloves' => ['Coated & General Purpose Gloves', 98, []],
                'cut_resistant_gloves' => ['Cut-Resistant Gloves', 96, []],
                'disposable_gloves' => ['Disposable Gloves', 69, []],
                'chemical_resistant_gloves' => ['Chemical-Resistant Gloves', 27, []],
                'leather_work_gloves' => ['Leather Work Gloves', 24, []],
            ]],
            'eye_face_protection' => ['Eye & Face Protection', 137, []],
            'respiratory_protection' => ['Respiratory Protection', 96, []],
            'head_protection' => ['Head Protection', 79, []],
            'first_aid_emergency' => ['First Aid & Emergency', 73, []],
            'protective_clothing' => ['Protective Clothing', 64, []],
            'hearing_protection' => ['Hearing Protection', 64, []],
            'heat_stress_hydration' => ['Heat Stress & Hydration', 50, []],
            'fall_protection' => ['Fall Protection', 42, []],
            'signs_barricades_lockout' => ['Signs, Barricades & Lockout', 35, []],
            'footwear' => ['Footwear', 33, []],
        ]],
        'tools' => ['Tools', 578, [
            'hand_tools' => ['Hand Tools', 289, [
                'wrenches' => ['Wrenches', 77, []],
                'hammers_bars_striking_tools' => ['Hammers, Bars & Striking Tools', 45, []],
                'pliers' => ['Pliers', 33, []],
                'measuring_layout' => ['Measuring & Layout', 31, []],
                'cutting_tools_knives_saws' => ['Cutting Tools, Knives & Saws', 30, []],
                'specialty_tools_kits' => ['Specialty Tools & Kits', 30, []],
                'shovels_site_tools' => ['Shovels & Site Tools', 16, []],
                'screwdrivers_sockets_ratchets' => ['Screwdrivers, Sockets & Ratchets', 16, []],
                'clamps_vises' => ['Clamps & Vises', 11, []],
            ]],
            'power_tools_batteries' => ['Power Tools & Batteries', 146, []],
            'pipe_plumbing_tools' => ['Pipe & Plumbing Tools', 143, []],
            'electrical_tools_testers' => ['Electrical Tools & Testers', 36, []],
        ]],
        'janitorial_facility' => ['Janitorial & Facility', 337, [
            'cleaners_soaps_disinfectants' => ['Cleaners, Soaps & Disinfectants', 115, []],
            'wipers_towels_tissue' => ['Wipers, Towels & Tissue', 94, []],
            'brooms_mops_floor_care' => ['Brooms, Mops & Floor Care', 80, []],
            'breakroom_trash' => ['Breakroom & Trash', 33, []],
            'absorbents_spill_control' => ['Absorbents & Spill Control', 15, []],
        ]],
        'welding' => ['Welding', 246, [
            'welding_helmets_gloves_apparel' => ['Welding Helmets, Gloves & Apparel', 109, []],
            'welding_tools_accessories' => ['Welding Tools & Accessories', 58, []],
            'torches_regulators_gas' => ['Torches, Regulators & Gas Equipment', 44, []],
            'wire_rods_electrodes' => ['Wire, Rods & Electrodes', 35, []],
        ]],
        'lubricants_chemicals_paint' => ['Lubricants, Chemicals & Paint', 210, [
            'adhesives_sealants_chemicals' => ['Adhesives, Sealants & Chemicals', 80, []],
            'paint_marking' => ['Paint & Marking', 69, []],
            'lubricants_penetrants' => ['Lubricants & Penetrants', 61, []],
        ]],
        'fasteners_tape_material_handling' => ['Fasteners, Tape & Material Handling', 204, [
            'storage_material_handling' => ['Storage & Material Handling', 102, []],
            'tape' => ['Tape', 54, []],
            'cable_ties_fasteners' => ['Cable Ties & Fasteners', 48, []],
        ]],
        'abrasives' => ['Abrasives', 123, [
            'grinding_cutting_finishing' => ['Grinding, Cutting & Finishing', 123, []],
        ]],
    ];

    /**
     * The six placeholder categories the shop launched with, and where their
     * old pages now send visitors. Specialty & Hard-to-Find stops being a
     * category: buyers are sent to Request a Quote for items not listed.
     */
    public const RETIRED = [
        'mro_materials' => ['mro-materials', '/tools'],
        'electrical_controls' => ['electrical-controls', '/tools/electrical-tools-testers'],
        'maintenance_supplies' => ['maintenance-supplies', '/janitorial-facility'],
        'fasteners_hardware' => ['fasteners-hardware', '/fasteners-tape-material-handling'],
        'specialty_hard_to_find' => ['specialty-hard-to-find', '/quote'],
    ];

    /**
     * Every category with its place in the tree, depth first.
     *
     * @return array<string, array{name: string, draft: int, parent: ?string, depth: int, leaf: bool}>
     */
    public static function flat(): array
    {
        $flat = [];
        $walk = static function (array $nodes, ?string $parent, int $depth) use (&$walk, &$flat): void {
            foreach ($nodes as $code => [$name, $draft, $children]) {
                $flat[$code] = ['name' => $name, 'draft' => $draft, 'parent' => $parent, 'depth' => $depth, 'leaf' => [] === $children];
                $walk($children, $code, $depth + 1);
            }
        };
        $walk(self::TREE, null, 0);

        return $flat;
    }

    /** @return list<string> the code and its ancestors, top first */
    public static function path(string $code): array
    {
        $flat = self::flat();
        $path = [];
        for ($at = $code; null !== $at; $at = $flat[$at]['parent']) {
            array_unshift($path, $at);
        }

        return $path;
    }
}
