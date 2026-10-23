<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractMigration;

final class Version20261023200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the product import state table (what each import last wrote, per product) (MySQL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_product_import_state (id INT AUTO_INCREMENT NOT NULL, product_code VARCHAR(255) NOT NULL, source VARCHAR(50) NOT NULL, imported_values JSON NOT NULL, last_imported_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_2832D789FAFD1239 (product_code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_product_import_state');
    }
}
