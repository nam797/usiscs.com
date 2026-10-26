<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractMigration;

final class Version20261023210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the supplier item table (our ORS Nasco cost per SKU) (MySQL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_supplier_item (id INT AUTO_INCREMENT NOT NULL, sku VARCHAR(255) NOT NULL, supplier VARCHAR(50) NOT NULL, cost_in_cents INT DEFAULT NULL, unit_sell VARCHAR(20) DEFAULT NULL, unit_conversion VARCHAR(50) DEFAULT NULL, status VARCHAR(255) DEFAULT NULL, synced_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_483EEE5BF9038C4 (sku), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_supplier_item');
    }
}
