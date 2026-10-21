<?php

declare(strict_types=1);

/**
 * Boots the kernel so phpstan/phpstan-doctrine can read the real ORM metadata
 * (see the doctrine.objectManagerLoader setting in phpstan.dist.neon).
 */

use App\Kernel;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Dotenv\Dotenv;
use Webmozart\Assert\Assert;

require dirname(__DIR__) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

$environment = $_SERVER['APP_ENV'] ?? 'dev';
Assert::string($environment);

$kernel = new Kernel($environment, false);
$kernel->boot();

$registry = $kernel->getContainer()->get('doctrine');
Assert::isInstanceOf($registry, ManagerRegistry::class);

return $registry->getManager();
