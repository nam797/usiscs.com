<?php

declare(strict_types=1);

namespace App\Command;

use App\Catalog\Category\CategoryClassifier;
use App\Catalog\Category\CategoryTree;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Dry run of moving the catalog into the approved category tree: sorts
 * every product with CategoryClassifier and reports how many products (and
 * SKUs, to compare with the draft's SKU counts) each category would get,
 * which categories come out thin, and which products no rule places.
 * --csv writes the full product list for review. Changes nothing.
 */
#[AsCommand(
    name: 'app:catalog:categorize',
    description: 'Dry run: sort every product into the approved category tree and report the counts (changes nothing).',
)]
final class CategorizeCatalogCommand extends Command
{
    /** The draft's rule: a category page with fewer products helps no one. */
    private const THIN = 10;

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('csv', null, InputOption::VALUE_REQUIRED, 'Write every product with its new category to this CSV file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<array{code: string, name: string, enabled: int|string, skus: int|string, current: ?string}> $products */
        $products = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT p.code, COALESCE(pt.name, p.code) AS name, p.enabled,
                   (SELECT COUNT(*) FROM sylius_product_variant v WHERE v.product_id = p.id) AS skus,
                   (SELECT tt.name FROM sylius_taxon_translation tt WHERE tt.translatable_id = p.main_taxon_id AND tt.locale = 'en_US') AS current
            FROM sylius_product p
            LEFT JOIN sylius_product_translation pt ON pt.translatable_id = p.id AND pt.locale = 'en_US'
            ORDER BY name
            SQL);

        $flat = CategoryTree::flat();
        $counts = array_fill_keys(array_keys($flat), ['products' => 0, 'skus' => 0]);
        $unplaced = [];
        $rows = [];
        foreach ($products as $product) {
            $code = CategoryClassifier::classify($product['name']);
            $rows[] = [$product, $code];
            if (null === $code) {
                $unplaced[] = $product;
                continue;
            }
            foreach (CategoryTree::path($code) as $node) {
                $counts[$node] = [
                    'products' => $counts[$node]['products'] + 1,
                    'skus' => $counts[$node]['skus'] + (int) $product['skus'],
                ];
            }
        }

        $table = [];
        $thin = [];
        foreach ($flat as $code => $node) {
            $table[] = [
                str_repeat('    ', $node['depth']).(0 === $node['depth'] ? '<info>'.$node['name'].'</info>' : $node['name']),
                $counts[$code]['products'],
                $counts[$code]['skus'],
                $node['draft'],
            ];
            if ($node['leaf'] && $counts[$code]['products'] < self::THIN) {
                $thin[] = sprintf('%s (%d)', $node['name'], $counts[$code]['products']);
            }
        }
        $io->title('Approved category tree: dry run (nothing changed)');
        $io->table(['Category', 'Products', 'SKUs', 'Draft SKUs'], $table);

        $placed = count($products) - count($unplaced);
        $io->text(sprintf('%d products sorted, %d placed, %d to place by hand.', count($products), $placed, count($unplaced)));
        if ([] !== $thin) {
            $io->warning(sprintf('Under %d products: %s.', self::THIN, implode(', ', $thin)));
        }
        if ([] !== $unplaced) {
            $io->section('No rule places these');
            $io->listing(array_map(static fn (array $p): string => sprintf('%s (%s)', $p['name'], $p['code']), $unplaced));
        }

        $csv = $input->getOption('csv');
        if (is_string($csv) && '' !== $csv) {
            $handle = fopen($csv, 'w');
            if (false === $handle) {
                $io->error(sprintf('Cannot write %s.', $csv));

                return Command::FAILURE;
            }
            fputcsv($handle, ['product_code', 'name', 'live', 'skus', 'current_category', 'department', 'category', 'subcategory'], escape: '');
            foreach ($rows as [$product, $code]) {
                $path = null === $code ? [] : array_map(static fn (string $node): string => $flat[$node]['name'], CategoryTree::path($code));
                fputcsv($handle, [
                    $product['code'], $product['name'], (int) $product['enabled'] ? 'yes' : 'no', (int) $product['skus'],
                    $product['current'] ?? '', $path[0] ?? '', $path[1] ?? '', $path[2] ?? '',
                ], escape: '');
            }
            fclose($handle);
            $io->success(sprintf('Wrote %d products to %s.', count($rows), $csv));
        }

        return Command::SUCCESS;
    }
}
