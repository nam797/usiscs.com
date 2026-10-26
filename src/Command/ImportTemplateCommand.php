<?php

declare(strict_types=1);

namespace App\Command;

use App\Catalog\Import\ProductRow;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes the spreadsheet to send the client: the import columns with an
 * example row, plus sheets listing the valid categories and what each
 * column means.
 */
#[AsCommand(
    name: 'app:catalog:import-template',
    description: 'Write a ready-to-fill product import spreadsheet (.xlsx).',
)]
final class ImportTemplateCommand extends Command
{
    private const COLUMN_HELP = [
        'sku' => ['Required', 'Your unique product code (e.g. the ORS item number). Letters, numbers and - _ . / + only. Re-importing a SKU updates that product.'],
        'name' => ['Required', 'Product name as shown in the shop.'],
        'category' => ['Required', 'One of the names (or codes) on the Categories sheet.'],
        'price' => ['Required', 'Selling price in USD, e.g. 12.99'],
        'description' => ['Optional', 'Full description for the product page.'],
        'short_description' => ['Optional', 'One-line summary.'],
        'manufacturer' => ['Optional', 'Brand / manufacturer.'],
        'part_number' => ['Optional', "Manufacturer's part number."],
        'unit_of_measure' => ['Optional', 'What the price is for: each, box, case of 12, case of 144 pairs, or as ORS writes it (144 PR = 1 CA).'],
        'stock_qty' => ['Optional', 'Units in stock. Leave blank to not track stock.'],
        'image_url' => ['Optional', 'Link (http/https) to a JPEG, PNG, WebP or GIF up to 5 MB.'],
        'go_live' => ['Optional', 'yes = show in the shop straight away (new products only). Blank/no = hidden draft for review.'],
    ];

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::OPTIONAL, 'Where to write the file', 'catalog-import-template.xlsx');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('path');
        \assert(is_string($path));

        $bold = (new Style())->withFontBold(true);
        $columns = [...ProductRow::REQUIRED_COLUMNS, ...ProductRow::OPTIONAL_COLUMNS];

        $writer = new Writer();
        $writer->openToFile($path);

        $writer->getCurrentSheet()->setName('Products');
        $writer->addRow(Row::fromValuesWithStyle($columns, $bold));
        $writer->addRow(Row::fromValues([
            'HB-500', 'Hex bolt 1/2"-13 x 2", grade 8', 'Fasteners & Hardware', '12.99',
            'Grade 8 zinc-plated hex cap screw.', 'Grade 8 hex bolt', 'Acme Fastener', 'AC-12-13-2',
            'box of 50', '120', '', 'no',
        ]));

        $writer->addNewSheetAndMakeItCurrent()->setName('Categories');
        $writer->addRow(Row::fromValuesWithStyle(['category (use either column)', 'code'], $bold));
        foreach (SetupCategoriesCommand::CATEGORIES as $code => $name) {
            $writer->addRow(Row::fromValues([$name, $code]));
        }

        $writer->addNewSheetAndMakeItCurrent()->setName('Column guide');
        $writer->addRow(Row::fromValuesWithStyle(['column', 'required?', 'what to enter'], $bold));
        foreach ($columns as $column) {
            $writer->addRow(Row::fromValues([$column, ...self::COLUMN_HELP[$column]]));
        }

        $writer->close();

        $io->success(sprintf('Wrote %s. Only the first sheet ("Products") is imported.', $path));

        return Command::SUCCESS;
    }
}
