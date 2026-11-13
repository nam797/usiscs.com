<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractMigration;

final class Version20261113120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Package protection: price tiers (app_package_protection_tier), the channel switch and the customer\'s choice on the order (MySQL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_package_protection_tier (id INT AUTO_INCREMENT NOT NULL, min_items_total INT NOT NULL, fee INT NOT NULL, UNIQUE INDEX UNIQ_C572B9E05CE6CDFB (min_items_total), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sylius_channel ADD package_protection_enabled TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE sylius_order ADD package_protection TINYINT(1) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_package_protection_tier');
        $this->addSql('ALTER TABLE sylius_channel DROP package_protection_enabled');
        $this->addSql('ALTER TABLE sylius_order DROP package_protection');
    }
}
