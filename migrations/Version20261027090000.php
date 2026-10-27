<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractMigration;

final class Version20261027090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fulfilment per variant (ORS direct / cross-dock / not confirmed) and a shipping note (MySQL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE sylius_product_variant ADD fulfilment VARCHAR(20) DEFAULT 'unconfirmed' NOT NULL, ADD shipping_note VARCHAR(255) DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sylius_product_variant DROP fulfilment, DROP shipping_note');
    }
}
