<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractMigration;

final class Version20261026160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pack unit per variant ("144 PR = 1 CA") and the supplier\'s own SKU on the import state (MySQL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sylius_product_variant ADD sell_unit VARCHAR(10) DEFAULT NULL, ADD pack_quantity INT DEFAULT NULL, ADD pack_unit VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_product_import_state ADD supplier_sku VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sylius_product_variant DROP sell_unit, DROP pack_quantity, DROP pack_unit');
        $this->addSql('ALTER TABLE app_product_import_state DROP supplier_sku');
    }
}
