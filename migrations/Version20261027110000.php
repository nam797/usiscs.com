<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractMigration;

final class Version20261027110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Purchase orders to distributors (lines, event timeline, per-distributor settings) and a distributor per variant (MySQL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_distributor_setting (code VARCHAR(50) NOT NULL, paused TINYINT DEFAULT 0 NOT NULL, manual_entry TINYINT DEFAULT 0 NOT NULL, note LONGTEXT DEFAULT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (code)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE app_purchase_order (id INT AUTO_INCREMENT NOT NULL, number VARCHAR(64) NOT NULL, distributor VARCHAR(50) NOT NULL, fulfilment VARCHAR(20) NOT NULL, state VARCHAR(30) NOT NULL, reference VARCHAR(64) DEFAULT NULL, attempts INT DEFAULT 0 NOT NULL, last_error LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, dispatched_at DATETIME DEFAULT NULL, last_attempt_at DATETIME DEFAULT NULL, submitted_at DATETIME DEFAULT NULL, order_id INT NOT NULL, UNIQUE INDEX UNIQ_FE18824C96901F54 (number), INDEX IDX_FE18824C8D9F6D38 (order_id), INDEX idx_app_purchase_order_state (state), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE app_purchase_order_event (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(30) NOT NULL, state VARCHAR(30) NOT NULL, message LONGTEXT DEFAULT NULL, actor VARCHAR(180) NOT NULL, payload JSON NOT NULL, occurred_at DATETIME NOT NULL, purchase_order_id INT NOT NULL, INDEX IDX_28DDEE74A45D7E6A (purchase_order_id), INDEX idx_app_purchase_order_event_occurred_at (occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE app_purchase_order_line (id INT AUTO_INCREMENT NOT NULL, variant_code VARCHAR(255) NOT NULL, supplier_sku VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, quantity INT NOT NULL, unit VARCHAR(10) DEFAULT NULL, unit_price_in_cents INT NOT NULL, unit_cost_in_cents INT DEFAULT NULL, purchase_order_id INT NOT NULL, order_item_id INT DEFAULT NULL, INDEX IDX_CE6831EEA45D7E6A (purchase_order_id), INDEX IDX_CE6831EEE415FB15 (order_item_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE app_purchase_order ADD CONSTRAINT FK_FE18824C8D9F6D38 FOREIGN KEY (order_id) REFERENCES sylius_order (id)');
        $this->addSql('ALTER TABLE app_purchase_order_event ADD CONSTRAINT FK_28DDEE74A45D7E6A FOREIGN KEY (purchase_order_id) REFERENCES app_purchase_order (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE app_purchase_order_line ADD CONSTRAINT FK_CE6831EEA45D7E6A FOREIGN KEY (purchase_order_id) REFERENCES app_purchase_order (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE app_purchase_order_line ADD CONSTRAINT FK_CE6831EEE415FB15 FOREIGN KEY (order_item_id) REFERENCES sylius_order_item (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE sylius_product_variant ADD distributor VARCHAR(50) DEFAULT NULL');
        // Every import so far is ORS Nasco's catalog; hand-made products stay unset.
        $this->addSql("UPDATE sylius_product_variant v INNER JOIN app_product_import_state s ON s.product_code = v.code SET v.distributor = 'ors_nasco'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sylius_product_variant DROP distributor');
        $this->addSql('DROP TABLE app_purchase_order_line');
        $this->addSql('DROP TABLE app_purchase_order_event');
        $this->addSql('DROP TABLE app_purchase_order');
        $this->addSql('DROP TABLE app_distributor_setting');
    }
}
