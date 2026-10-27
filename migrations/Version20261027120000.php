<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractMigration;

final class Version20261027120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Purchase order tracking: tracking numbers, shipped/back-ordered per line, state age and alert times (MySQL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_purchase_order_shipment (id INT AUTO_INCREMENT NOT NULL, tracking_number VARCHAR(100) NOT NULL, carrier VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, purchase_order_id INT NOT NULL, INDEX IDX_7E1ABDC8A45D7E6A (purchase_order_id), UNIQUE INDEX uniq_app_purchase_order_shipment_tracking (purchase_order_id, tracking_number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE app_purchase_order_shipment ADD CONSTRAINT FK_7E1ABDC8A45D7E6A FOREIGN KEY (purchase_order_id) REFERENCES app_purchase_order (id) ON DELETE CASCADE');

        // Existing rows: in their state since... at least their last event, else creation.
        $this->addSql('ALTER TABLE app_purchase_order ADD state_changed_at DATETIME DEFAULT NULL, ADD last_checked_at DATETIME DEFAULT NULL, ADD alerted_at DATETIME DEFAULT NULL');
        $this->addSql('UPDATE app_purchase_order p SET state_changed_at = COALESCE((SELECT MAX(e.occurred_at) FROM app_purchase_order_event e WHERE e.purchase_order_id = p.id), p.created_at)');
        $this->addSql('ALTER TABLE app_purchase_order MODIFY state_changed_at DATETIME NOT NULL');

        $this->addSql('ALTER TABLE app_purchase_order_line ADD quantity_shipped INT DEFAULT 0 NOT NULL, ADD quantity_backordered INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_purchase_order_line DROP quantity_shipped, DROP quantity_backordered');
        $this->addSql('ALTER TABLE app_purchase_order DROP state_changed_at, DROP last_checked_at, DROP alerted_at');
        $this->addSql('DROP TABLE app_purchase_order_shipment');
    }
}
