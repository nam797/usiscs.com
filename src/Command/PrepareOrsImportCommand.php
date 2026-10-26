<?php

declare(strict_types=1);

namespace App\Command;

use App\Catalog\Ors\OrsFeedConverter;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Builds the import sheet for the ORS catalog from ORS's price feed
 * spreadsheet. Review it (variant names, categories), then load it with
 * app:catalog:import.
 */
#[AsCommand(
    name: 'app:catalog:prepare-ors-import',
    description: 'Turn the ORS price feed spreadsheet into a sheet for app:catalog:import.',
)]
final class PrepareOrsImportCommand extends Command
{
    public function __construct(private readonly OrsFeedConverter $converter)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('feed', InputArgument::REQUIRED, sprintf('The .xlsx with the "%s" sheet', OrsFeedConverter::FEED_SHEET))
            ->addArgument('out', InputArgument::OPTIONAL, 'Where to write the import sheet', 'ors-import.xlsx')
            ->addOption('families', null, InputOption::VALUE_REQUIRED, sprintf('The .xlsx with the "%s" sheet (product families, brands); without it nothing is grouped', OrsFeedConverter::REVIEW_SHEET));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $feed = $input->getArgument('feed');
        $out = $input->getArgument('out');
        $families = $input->getOption('families');
        \assert(is_string($feed) && is_string($out) && (null === $families || is_string($families)));

        try {
            ['rows' => $rows, 'skipped' => $skipped] = $this->converter->convert($feed, $families);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $bold = (new Style())->withFontBold(true);
        $writer = new Writer();
        $writer->openToFile($out);
        $writer->getCurrentSheet()->setName('Products');
        $writer->addRow(Row::fromValuesWithStyle(OrsFeedConverter::COLUMNS, $bold));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_map(static fn (string $column): string => $row[$column] ?? '', OrsFeedConverter::COLUMNS)));
        }
        $writer->addNewSheetAndMakeItCurrent()->setName('Skipped');
        $writer->addRow(Row::fromValuesWithStyle(['sku', 'reason'], $bold));
        foreach ($skipped as $skip) {
            $writer->addRow(Row::fromValues([$skip['sku'], $skip['reason']]));
        }
        $writer->close();

        $groups = array_unique(array_filter(array_column($rows, 'group')));
        $io->table(['', 'SKUs'], [
            ['Ready to import', count($rows)],
            [sprintf('...sold as variants of %d products', count($groups)), count(array_filter(array_column($rows, 'group')))],
            ['Skipped (see the Skipped sheet)', count($skipped)],
        ]);
        $io->success(sprintf('Wrote %s. Review the variant names and categories, then: app:catalog:import %s --dry-run', $out, $out));

        return Command::SUCCESS;
    }
}
