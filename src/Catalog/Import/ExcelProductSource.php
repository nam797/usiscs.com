<?php

declare(strict_types=1);

namespace App\Catalog\Import;

use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reads the first sheet of an .xlsx file (or a .csv file). The first row
 * is the header; columns are matched by name ("Short Description",
 * "short-description" and "short_description" are all the same column), so
 * their order doesn't matter and extra columns are ignored. Streams rows,
 * so large sheets don't need to fit in memory.
 */
final class ExcelProductSource implements ProductSourceInterface
{
    public function __construct(private readonly string $path)
    {
    }

    public function name(): string
    {
        return 'excel';
    }

    public function rows(): iterable
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new \RuntimeException(sprintf('Cannot read "%s".', $this->path));
        }

        $reader = $this->reader();

        try {
            $reader->open($this->path);
            foreach ($reader->getSheetIterator() as $sheet) {
                $header = null;
                $rowNumber = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    ++$rowNumber;
                    $cells = array_map(self::text(...), $row->toArray());

                    if (null === $header) {
                        $header = array_values(array_map(self::columnName(...), $cells));
                        $this->assertRequiredColumns($header);
                        continue;
                    }

                    if ('' === implode('', $cells)) {
                        continue; // blank line
                    }

                    $values = [];
                    foreach ($header as $index => $column) {
                        if ('' !== $column) {
                            $values[$column] = $cells[$index] ?? '';
                        }
                    }

                    yield $rowNumber => $values;
                }

                if (null === $header) {
                    throw new \RuntimeException('The sheet is empty: expected a header row.');
                }

                break; // first sheet only
            }
        } catch (OpenSpoutException $e) {
            throw new \RuntimeException(sprintf('"%s" could not be read as a spreadsheet (%s). Save it as .xlsx or .csv and try again.', basename($this->path), $e->getMessage()), 0, $e);
        } finally {
            $reader->close();
        }
    }

    private function reader(): XlsxReader|CsvReader
    {
        // Empty rows are kept (and skipped in rows()) so the row numbers in
        // the import report match the ones the person sees in Excel.
        return match (strtolower(pathinfo($this->path, \PATHINFO_EXTENSION))) {
            'xlsx' => new XlsxReader(new XlsxOptions(SHOULD_PRESERVE_EMPTY_ROWS: true)),
            'csv' => new CsvReader(new CsvOptions(SHOULD_PRESERVE_EMPTY_ROWS: true)),
            default => throw new \RuntimeException(sprintf('Unsupported file type for "%s": use .xlsx or .csv.', basename($this->path))),
        };
    }

    /** @param list<string> $header */
    private function assertRequiredColumns(array $header): void
    {
        $missing = array_diff(ProductRow::REQUIRED_COLUMNS, $header);
        if ([] !== $missing) {
            throw new \RuntimeException(sprintf(
                'Missing required column(s): %s. The first row must be the header (download the template with app:catalog:import-template).',
                implode(', ', $missing),
            ));
        }
    }

    private static function columnName(string $cell): string
    {
        return trim((string) preg_replace('/[\s\-]+/', '_', mb_strtolower(trim($cell))), '_');
    }

    /** Cell value as the text a person would type into it. */
    private static function text(mixed $value): string
    {
        return match (true) {
            null === $value => '',
            is_string($value) => trim($value),
            is_bool($value) => $value ? 'yes' : 'no',
            is_int($value) => (string) $value,
            // Excel stores 12.5 for a cell showing 12.50; never use the
            // float's own string form, which can be 12.499999999.
            is_float($value) => rtrim(rtrim(sprintf('%.4F', $value), '0'), '.'),
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            default => '',
        };
    }
}
