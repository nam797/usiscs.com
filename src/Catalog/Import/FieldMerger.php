<?php

declare(strict_types=1);

namespace App\Catalog\Import;

/**
 * "The admin's edit always wins", decided field by field.
 *
 * The import remembers the last value it wrote to each field (the
 * snapshot). On the next import, a field whose current value still equals
 * that snapshot hasn't been touched by anyone, so the new incoming value
 * is written. A field whose current value differs was changed by an admin,
 * so it's kept -- the import never overwrites it.
 */
final class FieldMerger
{
    /**
     * @param array<string, scalar|null> $snapshot last values the import wrote
     * @param array<string, scalar|null> $current  values on the product now
     * @param array<string, scalar|null> $incoming values from this import
     */
    public function merge(array $snapshot, array $current, array $incoming): MergeResult
    {
        $toWrite = [];
        $kept = [];
        $newSnapshot = $snapshot;

        foreach ($incoming as $field => $value) {
            $now = $current[$field] ?? null;

            if (self::same($now, $value)) {
                // Already right (possibly an admin made the same change).
                $newSnapshot[$field] = $value;
                continue;
            }

            $untouched = array_key_exists($field, $snapshot)
                ? self::same($now, $snapshot[$field])
                // A field this import has never written: only fill it in
                // while nobody has given it a value.
                : self::same($now, null);

            if ($untouched) {
                $toWrite[$field] = $value;
                $newSnapshot[$field] = $value;
            } else {
                $kept[] = $field;
            }
        }

        return new MergeResult($toWrite, $kept, $newSnapshot);
    }

    /** Blank strings and null are the same "no value". */
    private static function same(mixed $a, mixed $b): bool
    {
        $a = '' === $a ? null : $a;
        $b = '' === $b ? null : $b;

        return $a === $b;
    }
}
