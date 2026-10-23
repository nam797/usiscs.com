<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Channel\Channel;
use App\Entity\Product\Product;
use App\Entity\Rfq\RfqRequest;
use App\Entity\Taxonomy\Taxon;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Cache\CacheItemPoolInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTaxonInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Product\Factory\ProductFactoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end tests: real kernel, real templates, real database.
 *
 * Needs the test database with the "e2e" fixture suite loaded
 * (config/packages/app_e2e_fixtures.yaml):
 *
 *   APP_ENV=test bin/console doctrine:database:create --if-not-exists
 *   APP_ENV=test bin/console doctrine:migrations:migrate -n
 *   APP_ENV=test bin/console sylius:fixtures:load e2e -n
 *
 *   APP_ENV=test bin/console app:catalog:setup-categories
 *
 * and built frontend assets (npm run build), since every page links them.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    protected const CHANNEL_CONTACT_EMAIL = 'sales@usiscs.test';

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // The form throttle keeps its counters in cache.app; without this,
        // submissions from earlier tests (or runs) would throttle later ones.
        $cache = static::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();

        // Every test starts with an empty RFQ queue (line items cascade).
        $this->entityManager()->createQuery('DELETE FROM '.RfqRequest::class)->execute();
    }

    protected function entityManager(): EntityManagerInterface
    {
        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $entityManager = $doctrine->getManagerForClass(RfqRequest::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    /** @return list<RfqRequest> */
    protected function savedRfqs(): array
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();

        return $entityManager->getRepository(RfqRequest::class)->findBy([], ['id' => 'ASC']);
    }

    /**
     * Creates (or re-enables/disables) a sellable product in the "default"
     * channel under the given category. Idempotent by code, so tests can
     * call it freely; products are left in the test database afterwards.
     */
    protected function ensureProduct(string $code, string $name, string $slug, string $taxonCode, bool $enabled = true): ProductInterface
    {
        $container = static::getContainer();
        $entityManager = $this->entityManager();

        $existing = $entityManager->getRepository(Product::class)->findOneBy(['code' => $code]);
        if ($existing instanceof ProductInterface) {
            $existing->setEnabled($enabled);
            $entityManager->flush();

            return $existing;
        }

        $channel = $entityManager->getRepository(Channel::class)->findOneBy(['code' => 'default']);
        self::assertInstanceOf(ChannelInterface::class, $channel, 'Load the "e2e" fixture suite first.');
        $taxon = $entityManager->getRepository(Taxon::class)->findOneBy(['code' => $taxonCode]);
        self::assertInstanceOf(TaxonInterface::class, $taxon, 'Run app:catalog:setup-categories first.');

        $productFactory = $container->get('sylius.factory.product');
        self::assertInstanceOf(ProductFactoryInterface::class, $productFactory);
        $product = $productFactory->createWithVariant();
        self::assertInstanceOf(ProductInterface::class, $product);
        $product->setCode($code);
        $product->setCurrentLocale('en_US');
        $product->setFallbackLocale('en_US');
        $product->setName($name);
        $product->setSlug($slug);
        $product->setEnabled($enabled);
        $product->addChannel($channel);
        $product->setMainTaxon($taxon);

        $productTaxonFactory = $container->get('sylius.factory.product_taxon');
        self::assertInstanceOf(FactoryInterface::class, $productTaxonFactory);
        $productTaxon = $productTaxonFactory->createNew();
        self::assertInstanceOf(ProductTaxonInterface::class, $productTaxon);
        $productTaxon->setTaxon($taxon);
        $product->addProductTaxon($productTaxon);

        $variant = $product->getVariants()->first();
        self::assertInstanceOf(ProductVariantInterface::class, $variant);
        $variant->setCode($code);

        $pricingFactory = $container->get('sylius.factory.channel_pricing');
        self::assertInstanceOf(FactoryInterface::class, $pricingFactory);
        $pricing = $pricingFactory->createNew();
        self::assertInstanceOf(ChannelPricingInterface::class, $pricing);
        $pricing->setChannelCode('default');
        $pricing->setPrice(1299);
        $variant->addChannelPricing($pricing);

        $entityManager->persist($product);
        $entityManager->flush();

        return $product;
    }
}
