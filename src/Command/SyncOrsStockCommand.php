<?php

declare(strict_types=1);

namespace App\Command;

use App\Catalog\Sync\StockSync;
use App\Supplier\OrsNasco\OrsApiException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Updates stock on imported products from the ORS Nasco bulk availability
 * API. Run by an Upsun cron shortly after each ORS refresh.
 */
#[AsCommand(
    name: 'app:ors:sync-stock',
    description: 'Update stock on imported products from ORS Nasco (total across all warehouses).',
)]
final class SyncOrsStockCommand extends Command
{
    public function __construct(private readonly StockSync $sync)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Call ORS and show what would change, without saving anything')
            ->addOption('sku', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only these SKUs (repeatable)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var list<string> $skus */
        $skus = $input->getOption('sku');

        try {
            $report = $this->sync->sync((bool) $input->getOption('dry-run'), [] === $skus ? null : $skus);
        } catch (OrsApiException $e) {
            $io->error($e->getMessage().'. Nothing was changed.');

            return Command::FAILURE;
        }

        return SyncReportOutput::print($io, $output, $report, 'Left as is (see warnings)');
    }
}
