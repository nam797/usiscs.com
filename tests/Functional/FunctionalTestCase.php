<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Rfq\RfqRequest;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Cache\CacheItemPoolInterface;
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
}
