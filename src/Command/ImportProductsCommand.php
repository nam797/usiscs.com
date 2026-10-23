<?php

declare(strict_types=1);

namespace App\Command;

use App\Catalog\Import\ExcelProductSource;
use App\Catalog\Import\ImportReport;
use App\Catalog\Import\ProductImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports products from the go-live spreadsheet (.xlsx or .csv).
 * New products arrive as hidden drafts unless their row says go_live=yes;
 * see App\Catalog\Import\ProductImporter for the full rules.
 */
#[AsCommand(
    name: 'app:catalog:import',
    description: 'Import products from an .xlsx or .csv file (new products arrive as hidden drafts).',
)]
final class ImportProductsCommand extends Command
{
    public function __construct(private readonly ProductImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the .xlsx or .csv file')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Check the file and show what would happen, without saving anything')
            ->setHelp(<<<'HELP'
                Columns (matched by header name, any order):

                  required: sku, name, category, price
                  optional: description, short_description, manufacturer, part_number,
                            unit_of_measure, stock_qty, image_url, go_live (yes/no)

                Get a ready-to-fill sheet with <info>app:catalog:import-template</info>.
                Re-importing the same SKUs updates them, except fields an admin has changed.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = $input->getArgument('file');
        \assert(is_string($file));
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $report = $this->importer->import(new ExcelProductSource($file), $dryRun);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->title($dryRun ? 'Dry run: nothing was saved' : 'Import finished');
        $io->table(['Result', 'Products'], [
            ['Created (hidden drafts)', $report->created - $report->createdLive],
            ['Created and live (go_live=yes)', $report->createdLive],
            ['Updated', $report->updated],
            ['Unchanged', $report->unchanged],
            ['Left alone (made by hand)', $report->untouched],
            ['Rows with errors (skipped)', $report->failed],
        ]);

        $rows = [];
        foreach ($report->messages() as $message) {
            if (ImportReport::INFO === $message['level'] && !$output->isVerbose()) {
                continue;
            }
            $rows[] = [$message['row'], $message['sku'] ?? '', $message['level'], $message['message']];
        }
        if ([] !== $rows) {
            $io->table(['Row', 'SKU', 'Level', 'Message'], $rows);
        }
        if (!$output->isVerbose() && [] !== $report->messages(ImportReport::INFO)) {
            $io->note('Run with -v to see a line for every product.');
        }

        if ($report->hasErrors()) {
            $io->warning(sprintf('%d row(s) were skipped because of errors; fix them in the sheet and import it again.', $report->failed));

            return Command::FAILURE;
        }

        $io->success($dryRun ? 'The file is valid.' : 'Done.');

        return Command::SUCCESS;
    }
}
