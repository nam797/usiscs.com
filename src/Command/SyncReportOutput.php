<?php

declare(strict_types=1);

namespace App\Command;

use App\Catalog\Import\ImportReport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** The report table shared by the supplier sync commands. */
final class SyncReportOutput
{
    /** @return Command::SUCCESS */
    public static function print(SymfonyStyle $io, OutputInterface $output, ImportReport $report, string $leftAloneLabel): int
    {
        $io->title($report->dryRun ? 'Dry run: nothing was saved' : 'Sync finished');
        $io->table(['Result', 'Products'], [
            ['Updated', $report->updated],
            ['Unchanged', $report->unchanged],
            [$leftAloneLabel, $report->untouched],
        ]);

        $rows = [];
        foreach ($report->messages() as $message) {
            if (ImportReport::INFO === $message['level'] && !$output->isVerbose()) {
                continue;
            }
            $rows[] = [$message['sku'] ?? '', $message['level'], $message['message']];
        }
        if ([] !== $rows) {
            $io->table(['SKU', 'Level', 'Message'], $rows);
        }
        if (!$output->isVerbose() && [] !== $report->messages(ImportReport::INFO)) {
            $io->note('Run with -v to see a line for every change.');
        }

        $io->success('Done.');

        return Command::SUCCESS;
    }
}
