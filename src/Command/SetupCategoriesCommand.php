<?php

declare(strict_types=1);

namespace App\Command;

use App\Catalog\Category\CategoryTreeBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates the storefront's category tree -- the one the client approved,
 * CategoryTree -- and makes it the channel's menu. Then retires the six
 * launch placeholder categories: deletes the ones no product uses, hides
 * the rest (move their products with app:catalog:categorize --apply).
 *
 * Safe to run any number of times: it only adds what is missing and never
 * renames, moves or re-enables anything an admin has changed. It is
 * deliberately NOT run on deploy, so a category the client deletes on
 * purpose stays deleted.
 */
#[AsCommand(
    name: 'app:catalog:setup-categories',
    description: 'Create the product category tree and attach it to the shop menu (idempotent).',
)]
final class SetupCategoriesCommand extends Command
{
    public const ROOT_CODE = CategoryTreeBuilder::ROOT_CODE;
    public const ROOT_NAME = CategoryTreeBuilder::ROOT_NAME;

    /** @param ChannelRepositoryInterface<ChannelInterface> $channelRepository */
    public function __construct(
        private readonly CategoryTreeBuilder $builder,
        private readonly ChannelRepositoryInterface $channelRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $created = $this->builder->createTree();
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $root = $this->builder->find(self::ROOT_CODE);
        $menus = [];
        foreach ($this->channelRepository->findAll() as $channel) {
            if (null === $channel->getMenuTaxon() && null !== $root) {
                $channel->setMenuTaxon($root);
                $menus[] = (string) $channel->getCode();
            }
        }
        $this->entityManager->flush();

        $retired = $this->builder->retirePlaceholders();

        if ([] === $created && [] === $menus && [] === $retired['removed'] && [] === $retired['hidden']) {
            $io->success('Categories already set up; nothing to do.');

            return Command::SUCCESS;
        }

        if ([] !== $created) {
            $io->listing($created);
        }
        if ([] !== $menus) {
            $io->text(sprintf('Shop menu set to "%s" for channel(s): %s', self::ROOT_NAME, implode(', ', $menus)));
        }
        if ([] !== $retired['removed']) {
            $io->text(sprintf('Removed the old categories: %s.', implode(', ', $retired['removed'])));
        }
        if ([] !== $retired['hidden']) {
            $io->warning(sprintf('Hidden, still holding products: %s. Move them with app:catalog:categorize --apply, then run this again.', implode(', ', $retired['hidden'])));
        }
        $io->success(sprintf('Created %d categor%s.', count($created), 1 === count($created) ? 'y' : 'ies'));

        return Command::SUCCESS;
    }
}
