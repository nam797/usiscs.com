<?php

declare(strict_types=1);

namespace App\Catalog\Ors;

/**
 * Places an ORS SKU in one of the six launch categories
 * (SetupCategoriesCommand) by keywords in its name, falling back on the
 * USISCS segment. Stand-in until the client approves the full category
 * tree; replace it then.
 */
final class InterimCategory
{
    private const RULES = [
        'Safety & PPE' => ['glove', 'glasses', 'goggle', 'respirator', 'earplug', 'earmuff', 'hard hat', 'helmet', 'harness', 'lanyard', 'vest', 'coverall', 'boot', 'first aid', 'face shield', 'safety', 'hearing', 'eyewash'],
        'Fasteners & Hardware' => ['cable tie', 'anchor', 'screw', 'bolt', 'nut', 'washer', 'clamp', 'hook', 'padlock', 'hinge', 'strap', 'rivet'],
        'Electrical & Controls' => ['battery', 'batteries', 'cable', 'wire', 'electrical', 'voltage', 'tester', 'extension cord', 'flashlight', 'light', 'cord'],
        'Maintenance Supplies' => ['clean', 'wipe', 'towel', 'brush', 'broom', 'mop', 'degreaser', 'detergent', 'lubric', 'grease', 'soap', 'sealant', 'paint', 'tape', 'marker', 'absorb', 'sorbent', 'trash', 'liner'],
    ];

    public static function for(string $segment, string $name): string
    {
        $text = mb_strtolower($name);
        foreach (self::RULES as $category => $words) {
            foreach ($words as $word) {
                if (str_contains($text, $word)) {
                    return $category;
                }
            }
        }

        return 'safety' === mb_strtolower($segment) ? 'Safety & PPE' : 'MRO Materials';
    }
}
