<?php

declare(strict_types=1);

namespace App\Command;

use App\Catalog\Sync\CostSync;
use App\Supplier\OrsNasco\OrsApiException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Records our ORS Nasco cost for each imported product and warns about any
 * product priced at or below it. Never changes selling prices.
 */
#[AsCommand(
    name: 'app:ors:sync-cost',
    description: 'Record our ORS Nasco cost per imported product and flag any priced at or below cost.',
)]
final class SyncOrsCostCommand extends Command
{
    public function __construct(private readonly CostSync $sync)
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

        return SyncReportOutput::print($io, $output, $report, 'No usable cost (see warnings)');
    }
}
