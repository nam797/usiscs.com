<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Creates the storefront's category tree -- the six product categories
 * already advertised on /services -- and makes it the channel's menu, so
 * the categories show in the shop navigation.
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
    public const ROOT_CODE = 'category';
    public const ROOT_NAME = 'Products';

    /** Same order and wording as the "Product Categories" list on /services. */
    public const CATEGORIES = [
        'mro_materials' => 'MRO Materials',
        'electrical_controls' => 'Electrical & Controls',
        'maintenance_supplies' => 'Maintenance Supplies',
        'safety_ppe' => 'Safety & PPE',
        'fasteners_hardware' => 'Fasteners & Hardware',
        'specialty_hard_to_find' => 'Specialty & Hard-to-Find',
    ];

    /**
     * @param TaxonRepositoryInterface<TaxonInterface> $taxonRepository
     * @param FactoryInterface<TaxonInterface>    $taxonFactory
     * @param RepositoryInterface<LocaleInterface> $localeRepository
     * @param ChannelRepositoryInterface<ChannelInterface> $channelRepository
     */
    public function __construct(
        private readonly TaxonRepositoryInterface $taxonRepository,
        #[Autowire(service: 'sylius.factory.taxon')]
        private readonly FactoryInterface $taxonFactory,
        #[Autowire(service: 'sylius.repository.locale')]
        private readonly RepositoryInterface $localeRepository,
        private readonly ChannelRepositoryInterface $channelRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $localeCodes = [];
        foreach ($this->localeRepository->findAll() as $locale) {
            if (null !== $locale->getCode()) {
                $localeCodes[] = $locale->getCode();
            }
        }

        if ([] === $localeCodes) {
            $io->error('No locales configured. Install Sylius (or load fixtures) first.');

            return Command::FAILURE;
        }

        $created = [];

        $root = $this->findTaxon(self::ROOT_CODE);
        if (null === $root) {
            $root = $this->createTaxon(self::ROOT_CODE, self::ROOT_NAME, 'products', $localeCodes);
            $created[] = self::ROOT_NAME;
        }

        $slugger = new AsciiSlugger();
        $position = 0;
        foreach (self::CATEGORIES as $code => $name) {
            if (null === $this->findTaxon($code)) {
                // No "products/" prefix: categories live at the site root (/safety-ppe).
                $slug = (string) $slugger->slug($name)->lower();
                $child = $this->createTaxon($code, $name, $slug, $localeCodes);
                $child->setPosition($position);
                $root->addChild($child);
                $created[] = $name;
            }
            ++$position;
        }

        $menus = [];
        foreach ($this->channelRepository->findAll() as $channel) {
            if (null === $channel->getMenuTaxon()) {
                $channel->setMenuTaxon($root);
                $menus[] = (string) $channel->getCode();
            }
        }

        $this->entityManager->flush();

        if ([] === $created && [] === $menus) {
            $io->success('Categories already set up; nothing to do.');

            return Command::SUCCESS;
        }

        if ([] !== $created) {
            $io->listing($created);
        }
        if ([] !== $menus) {
            $io->text(sprintf('Shop menu set to "%s" for channel(s): %s', self::ROOT_NAME, implode(', ', $menus)));
        }
        $io->success(sprintf('Created %d categor%s.', count($created), 1 === count($created) ? 'y' : 'ies'));

        return Command::SUCCESS;
    }

    private function findTaxon(string $code): ?TaxonInterface
    {
        return $this->taxonRepository->findOneBy(['code' => $code]);
    }

    /** @param non-empty-list<string> $localeCodes */
    private function createTaxon(string $code, string $name, string $slug, array $localeCodes): TaxonInterface
    {
        $taxon = $this->taxonFactory->createNew();
        $taxon->setCode($code);
        $taxon->setEnabled(true);
        $taxon->setCurrentLocale($localeCodes[0]);
        $taxon->setFallbackLocale($localeCodes[0]);

        foreach ($localeCodes as $localeCode) {
            $translation = $taxon->getTranslation($localeCode);
            $translation->setName($name);
            $translation->setSlug($slug);
        }

        $this->entityManager->persist($taxon);

        return $taxon;
    }
}
