<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Shipping\ShippingMethod;
use App\Shipping\Carrier;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Shipping\Calculator\DefaultCalculators;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Creates the UPS and FedEx shipping methods, each a flat fee the client
 * changes in Sylius admin → Shipping methods. UPS starts enabled; FedEx
 * starts disabled and is switched on by ticking Enabled.
 *
 * The first time it creates them, it disables the old $15 "standard" test
 * method. Safe to run any number of times: it only adds methods that are
 * missing, and never changes a fee, name or switch an admin has set. Not
 * run on deploy, so a method the client deletes stays deleted.
 */
#[AsCommand(
    name: 'app:shipping:setup-methods',
    description: 'Create the UPS and FedEx flat-fee shipping methods (idempotent).',
)]
final class SetupShippingMethodsCommand extends Command
{
    public const ZONE_CODE = 'US';
    public const TEST_METHOD_CODE = 'standard';

    /**
     * Placeholder fees (cents) until the client sets theirs.
     *
     * @var array<string, array{carrier: Carrier, name: string, description: string, amount: int, enabled: bool}>
     */
    public const METHODS = [
        'ups_ground' => ['carrier' => Carrier::Ups, 'name' => 'UPS Ground', 'description' => '1–5 business days once it ships.', 'amount' => 1500, 'enabled' => true],
        'ups_2day' => ['carrier' => Carrier::Ups, 'name' => 'UPS 2nd Day Air', 'description' => '2 business days once it ships.', 'amount' => 3500, 'enabled' => true],
        'ups_next_day' => ['carrier' => Carrier::Ups, 'name' => 'UPS Next Day Air', 'description' => 'Next business day once it ships.', 'amount' => 6000, 'enabled' => true],
        'fedex_ground' => ['carrier' => Carrier::Fedex, 'name' => 'FedEx Ground', 'description' => '1–5 business days once it ships.', 'amount' => 1500, 'enabled' => false],
        'fedex_2day' => ['carrier' => Carrier::Fedex, 'name' => 'FedEx 2Day', 'description' => '2 business days once it ships.', 'amount' => 3500, 'enabled' => false],
        'fedex_overnight' => ['carrier' => Carrier::Fedex, 'name' => 'FedEx Standard Overnight', 'description' => 'Next business day once it ships.', 'amount' => 6000, 'enabled' => false],
    ];

    /**
     * @param FactoryInterface<ShippingMethod>             $shippingMethodFactory
     * @param RepositoryInterface<ShippingMethod>          $shippingMethodRepository
     * @param RepositoryInterface<ZoneInterface>           $zoneRepository
     * @param RepositoryInterface<LocaleInterface>         $localeRepository
     * @param ChannelRepositoryInterface<ChannelInterface> $channelRepository
     */
    public function __construct(
        #[Autowire(service: 'sylius.factory.shipping_method')]
        private readonly FactoryInterface $shippingMethodFactory,
        #[Autowire(service: 'sylius.repository.shipping_method')]
        private readonly RepositoryInterface $shippingMethodRepository,
        #[Autowire(service: 'sylius.repository.zone')]
        private readonly RepositoryInterface $zoneRepository,
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
        $zone = $this->zoneRepository->findOneBy(['code' => self::ZONE_CODE]);
        $channels = $this->channelRepository->findAll();
        if ([] === $localeCodes || !$zone instanceof ZoneInterface || [] === $channels) {
            $io->error(sprintf('Needs at least one locale, a channel and the "%s" zone. Install Sylius (or load fixtures) first.', self::ZONE_CODE));

            return Command::FAILURE;
        }

        $created = [];
        $position = 0;
        foreach (self::METHODS as $code => $method) {
            if (null === $this->shippingMethodRepository->findOneBy(['code' => $code])) {
                $this->create($code, $method, $position, $zone, $channels, $localeCodes);
                $created[] = sprintf('%s ($%s, %s)', $method['name'], number_format($method['amount'] / 100, 2), $method['enabled'] ? 'enabled' : 'disabled');
            }
            ++$position;
        }

        if ([] === $created) {
            $io->success('Shipping methods already set up; nothing to do.');

            return Command::SUCCESS;
        }

        $testMethod = $this->shippingMethodRepository->findOneBy(['code' => self::TEST_METHOD_CODE]);
        if ($testMethod instanceof ShippingMethod && $testMethod->isEnabled()) {
            $testMethod->disable();
            $created[] = sprintf('Disabled the "%s" test method', self::TEST_METHOD_CODE);
        }

        $this->entityManager->flush();

        $io->listing($created);
        $io->success('Set the real fees in Sylius admin → Shipping methods. Enable the FedEx methods there to offer FedEx.');

        return Command::SUCCESS;
    }

    /**
     * @param array{carrier: Carrier, name: string, description: string, amount: int, enabled: bool} $method
     * @param array<ChannelInterface>                                                                  $channels
     * @param non-empty-list<string>                                                                   $localeCodes
     */
    private function create(string $code, array $method, int $position, ZoneInterface $zone, array $channels, array $localeCodes): void
    {
        $shippingMethod = $this->shippingMethodFactory->createNew();
        $shippingMethod->setCode($code);
        $shippingMethod->setCarrier($method['carrier']);
        $shippingMethod->setZone($zone);
        $shippingMethod->setPosition($position);
        $shippingMethod->setEnabled($method['enabled']);
        $shippingMethod->setCalculator(DefaultCalculators::FLAT_RATE);

        $configuration = [];
        foreach ($channels as $channel) {
            $shippingMethod->addChannel($channel);
            $configuration[(string) $channel->getCode()] = ['amount' => $method['amount']];
        }
        $shippingMethod->setConfiguration($configuration);

        $shippingMethod->setCurrentLocale($localeCodes[0]);
        $shippingMethod->setFallbackLocale($localeCodes[0]);
        foreach ($localeCodes as $localeCode) {
            $translation = $shippingMethod->getTranslation($localeCode);
            $translation->setName($method['name']);
            $translation->setDescription($method['description']);
        }

        $this->entityManager->persist($shippingMethod);
    }
}
