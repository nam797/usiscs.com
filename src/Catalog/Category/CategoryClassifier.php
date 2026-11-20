<?php

declare(strict_types=1);

namespace App\Catalog\Category;

/**
 * Places a product in the approved category tree (CategoryTree) by what its
 * name says it is. Product names are the ORS product family names, brand
 * first ("Ridgid® Heavy-Duty Pipe Cutters"), so the brand counts too.
 *
 * Rules are tried in order and the first match wins, so the specific ones
 * come before the general ones: welding gloves before gloves, respirator
 * filters before vacuum filters, electrical tape and barricade tape before
 * tape. Gloves and hand tools are then sorted into their third level.
 * Returns null when nothing matches: those are placed by hand.
 */
final class CategoryClassifier
{
    /** @var list<array{string, string}> category code => pattern on the lowercased name */
    private const RULES = [
        // Names that would otherwise hit a rule further down.
        ['brooms_mops_floor_care', '/shop-vac|wet\/dry/'],
        ['specialty_tools_kits', '/sight glass/'],
        ['cutting_tools_knives_saws', '/putty knives/'],

        // Before welding and eye protection: welding-rated respirators and fall gear.
        ['respiratory_protection', '/respirat|cartridge|particulate filter|\bpapr\b|facepiece|gas mask|canisters|versaflo|pureflo|filters\/cartridges|adapters & retainers|bullard® eva/'],
        ['fall_protection', '/harness|lanyard|lifeline|fall limiter|fall arrest|rope grab|carabiner|\banchors\b|vi-go|saf-t-|confined space|edge cables|relief step|glideloc|glove clip/'],

        // Welding
        ['welding_helmets_gloves_apparel', '/weld(ing|er\'?s?) (glove|helmet|jacket|blanket|curtain)|welders? gloves|(mig|tig|stick)( welding)? gloves|mig\/|tig\/|\/tig|\/mig|welding work gloves|auto-darkening|fr sateen|curtain frames|cover lens|filter plate|safety plate|\bspats\b|roll goods|tigerhood|pipeliner/'],
        ['wire_rods_electrodes', '/electrode(?!s? holder| oven)|welding wire|flux cored|\b7018\b/'],
        ['torches_regulators_gas', '/torch|regulator|cutting tips|cutting and welding|cutting, heating|welding hose|hose repair|quick connect|totes with tanks|bernzomatic|tank wrench/'],
        ['welding_tools_accessories', '/chipping hammer|soapstone|spark lighter|flints|tip cleaner|nozzle dip|anti-spatter|flange wizard|pipe templates|magnetic holders|electrode holder|electrode oven|ground clamp|welding cable|mig gun|contact tip|welding pliers|leather holster|lube pads|wrap-a-round/'],

        // Safety & PPE
        ['hearing_protection', '/earplug|earmuff|foam plugs|sparkplugs|plugstation|soundcontrol/'],
        ['heat_stress_hydration', '/gatorade|sqwincher|electrolyte|medi-lyte|cooling|chill-its|miracool|warmer|warming pack|heat pax|coolers|water jug/'],
        ['first_aid_emergency', '/first aid|eye ?wash|eye (and skin )?flush|eyesaline|fendall|bandage|cold pack|antibiotic|ibuprofen|acetaminophen|petroleum jelly|burn|bloodborne|extinguisher|gas detector|sunscreen|insect (repellent|shield)/'],
        ['eye_face_protection', '/glasses|eyewear|goggle|face ?shield|facehield|visor|lens|sideshield|fog eliminator|towelette|speedy-loop|cap coil spring/'],
        ['head_protection', '/hard hat|helmet|sweatband|suspension|headgear|skullgard|v-gard|protective (caps|hats)|sun shields|reversible caps/'],
        ['signs_barricades_lockout', '/lockout|padlock|lock box|\btags\b|cones|barricade|barrier tape|delineator|pennants|safety fence|safety paddle|warning kit|signs|flagging tape|hasps/'],
        ['protective_clothing', '/boot covers|coverall|tyvek|lab coat|vests|rainsuit|apron|work shirts|spray socks|tychem/'],
        ['footwear', '/\bboots\b|waders|overshoes/'],
        ['hand_protection', '/glove|mitten|heatworx/'],

        // Abrasives (before power tools and brushes)
        ['grinding_cutting_finishing', '/grinding wheel|cut-off wheel|cutting wheel|flap disc|fibre disc|fiber disc|depressed center|type 27|slicer|wire (end|cup|wheel)|knot|stringer bead|crimped wire|scratch brush|\bfiles\b|surface-conditioning|hand pads/'],

        // Containers, chargers and socket sets whose names sound like paint,
        // storage or brooms.
        ['storage_material_handling', '/pails/'],
        ['power_tools_batteries', '/charger|guardair/'],
        ['screwdrivers_sockets_ratchets', '/socket sets/'],

        // Lubricants, chemicals & paint (before pipe tools and cleaners)
        ['adhesives_sealants_chemicals', '/adhesive|sealant|threadlocker|epox|putty|rtv|gasket|primer n|leak detector|cold weld|expanding foam|concrete repair|brake (parts )?cleaner|contact cleaner|precision cleaner|electric(al)? parts|carburetor|insecticide|magnaflux|torque seal|pipe primer|starting fluid/'],
        ['lubricants_penetrants', '/lubric|lube|(?<!de)grease|penetrat|anti-se[ie]ze|never-seez|seez|cutting (oil|fluid|paste)|thread cutting|tap magic|kroil|tool oil|mystery oil|oilers|rust inhibitor|corrosion inhibitor|fluid film|moly|couplers|wd-40® multi|lubriplate/'],
        ['paint_marking', '/paint(?!er)|marker|marking|primer|galvaniz(e|ing)|galva |enamel|layout fluid|welders pencil|silver-streak|coatings|spray gun/'],

        // Janitorial & facility
        ['absorbents_spill_control', '/sorbent|absorbent|spill/'],
        ['breakroom_trash', '/trash|liner|contractor bags|dish detergent|cups|coffee|round container|cigarette/'],
        ['brooms_mops_floor_care', '/cleaning brushes/'],
        ['cleaners_soaps_disinfectants', '/clean|degreaser|disinfect|soap|hand ?wash|sanitiz|detergent|bleach|polish|windex|lysol|pine-sol|scrubbing|magic eraser|skin conditioner|hand scrub|instant shine|germicidal/'],
        ['wipers_towels_tissue', '/wiper|towel|tissue|wypall|rags|cloths|kimwipes|toilet paper|little joe|wiping|scouring pad/'],
        ['brooms_mops_floor_care', '/broom|mop|dust ?pan|squeegee|sweep|\bbrush(es)?\b|(?<![a-z])vac\b|vacuum|dust bags|wringer|(?<!hammer )handles|braces|dusters|coco mats|nabber/'],

        // Fasteners, tape & material handling
        ['electrical_tools_testers', '/electrical tape|electrical color coding|vinyl electrical|temflex|extension cord|light|fish tape/'],
        ['measuring_layout', '/tape measure|tape rule|tapes? refills|gauging|surveying tape|maggrip|speed mark|plumb bob|level|squares|ruler|folding rules|gage pole|water finding/'],
        ['tape', '/tape/'],
        ['cable_ties_fasteners', '/cable ties|hook fasteners|loop fasteners|tie-down|straps|tie wires|mechanics wires|\brope\b|cords?\b/'],
        ['storage_material_handling', '/tool ?box|chest|packout|toughsystem|organizer|\bbins\b|safety can|cabinet|hose reel|wheel chock|hand truck|dollies|bags|backpack|buckets|\bhooks\b|piano box|site-vault|waste can|tarps|hoses|hand pump|safety cables/'],

        // Tools
        ['pliers', '/plier/'],
        ['electrical_tools_testers', '/voltage|tester|detector|cable (cutter|stripper|trimmer)|wire stripper|crimp|fish tape|knockout|punch|indenter|sheave|pulling grip|haven|thermometer|electrician|pvc (cutter|heater)|extension cord/'],
        ['grinding_cutting_finishing', '/abrasive/'],
        ['power_tools_batteries', '/batter(y|ies)|\bm12\b|\bm18\b|20v|60v|cordless|flexvolt|grinder|impact ?wrench|impactool|air hammer|angle wrench|drill(?!ing)|band saw|reciprocating|sawzall|hackzall|circular saw|miter saw|cut-off (tool|saw)|demolition hammer|rotary hammer|screw gun|multi-tool|combo kit|charger|dust shroud|work station|step bit|unibit|air gun/'],
        ['pipe_plumbing_tools', '/pipe|threader|threading|tub(e|ing) cutter|tube bender|benders|groover|flaring|vises?(?![-\w])|die head|\bdies\b|reamer|\btaps\b|tapping tool|strap wrench|chain wrench|cutter (head|wheel)|beveller|strut|rapwrench|rapidgrip|model 700/'],
        ['cutting_tools_knives_saws', '/knife|knives|blades?\b|snips|shears|hacksaw|bolt cutter|scraper/'],
        ['screwdrivers_sockets_ratchets', '/screwdriver|nut driver|socket|\bratchets?\b/'],
        ['specialty_tools_kits', '/tool (set|kit)|tool kits|mechanics tool|extractor|mirror|staple gun|caulking gun|sight glass|packing hook|sand strainer|babbit/'],
        ['wrenches', '/wrench|hex key/'],
        ['hammers_bars_striking_tools', '/hammer|sledge|crowbar|\bbars\b|bull[- ]?pins?/'],
        ['shovels_site_tools', '/shovel|scoop|wheelbarrow|sprayer/'],
        ['clamps_vises', '/clamp/'],
    ];

    /** @var list<array{string, string}> glove type => pattern; anything else is coated & general purpose */
    private const GLOVES = [
        ['disposable_gloves', '/disposable|exam|n-dex|touchntuff|microflex|kimtech|trilites|sensatouch|kleenguard™ g10|box of 100/'],
        ['chemical_resistant_gloves', '/chemical|solvex|butyl|neoprene|stansolv|stanzoil|triple polymer|gauntlet|canners|unsupported|showa® 772|black knight|double[- ]dip|oil hauler/'],
        ['cut_resistant_gloves', '/cut[- ]resist|cutpro|cut™|hppe|(?<!wool-lined )kevlar|helix|flextech|s-tex|11-727|58-530|cut resistant/'],
        ['leather_work_gloves', '/leather|cowhide|goatskin|pigskin|deerskin|driver\'?s? gloves|drivers gloves|cowskin|\bsplit\b/'],
    ];

    /** @return string|null the code of a leaf category, null when no rule matches */
    public static function classify(string $name): ?string
    {
        $text = mb_strtolower($name);
        foreach (self::RULES as [$code, $pattern]) {
            if (1 === preg_match($pattern, $text)) {
                return 'hand_protection' === $code ? self::gloveType($text) : $code;
            }
        }

        return null;
    }

    private static function gloveType(string $text): string
    {
        foreach (self::GLOVES as [$code, $pattern]) {
            if (1 === preg_match($pattern, $text)) {
                return $code;
            }
        }

        return 'coated_general_purpose_gloves';
    }
}
