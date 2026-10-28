<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractMigration;

final class Version20261028120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sources: a SKU can have several distributors (app_supplier_item per SKU and distributor, with their SKU, fulfilment, stock); moves distributor and fulfilment off the variant (MySQL)';
    }

    public function up(Schema $schema): void
    {
        // One row per SKU and distributor, tied to the variant.
        $this->addSql('DROP INDEX UNIQ_483EEE5BF9038C4 ON app_supplier_item');
        $this->addSql('ALTER TABLE app_supplier_item ADD supplier_sku VARCHAR(255) DEFAULT NULL, ADD fulfilment VARCHAR(20) DEFAULT \'unconfirmed\' NOT NULL, ADD enabled TINYINT DEFAULT 1 NOT NULL, ADD stock INT DEFAULT NULL, ADD stock_synced_at DATETIME DEFAULT NULL, ADD variant_id INT DEFAULT NULL');

        // Existing cost rows (all ORS) join their variant; rows for deleted variants go.
        $this->addSql('UPDATE app_supplier_item s JOIN sylius_product_variant v ON v.code = s.sku SET s.variant_id = v.id, s.fulfilment = v.fulfilment, s.supplier = COALESCE(v.distributor, s.supplier)');
        $this->addSql('DELETE FROM app_supplier_item WHERE variant_id IS NULL');

        // Every variant with a distributor gets that distributor as a source, with its fulfilment.
        $this->addSql(<<<'SQL'
            INSERT INTO app_supplier_item (variant_id, sku, supplier, fulfilment, enabled, synced_at)
            SELECT v.id, v.code, v.distributor, v.fulfilment, 1, NOW()
            FROM sylius_product_variant v
            WHERE v.distributor IS NOT NULL AND v.code IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM app_supplier_item s WHERE s.variant_id = v.id AND s.supplier = v.distributor)
            SQL);

        // The distributor's own SKU moves from the import state to the source.
        $this->addSql('UPDATE app_supplier_item s JOIN app_product_import_state st ON st.product_code = s.sku SET s.supplier_sku = st.supplier_sku WHERE st.supplier_sku IS NOT NULL');

        $this->addSql('ALTER TABLE app_supplier_item MODIFY variant_id INT NOT NULL');
        $this->addSql('ALTER TABLE app_supplier_item ADD CONSTRAINT FK_483EEE5B3B69A9AF FOREIGN KEY (variant_id) REFERENCES sylius_product_variant (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_483EEE5B3B69A9AF ON app_supplier_item (variant_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_app_supplier_item_sku_supplier ON app_supplier_item (sku, supplier)');

        // Whose sheet the product data came from: every import so far is ORS Nasco's.
        $this->addSql('ALTER TABLE app_product_import_state ADD distributor VARCHAR(50) DEFAULT \'ors_nasco\' NOT NULL, DROP supplier_sku');
        $this->addSql('ALTER TABLE app_product_import_state ALTER distributor DROP DEFAULT');

        $this->addSql('ALTER TABLE sylius_product_variant DROP fulfilment, DROP distributor');
    }

    public function down(Schema $schema): void
    {
        // Back to one distributor per variant: its ORS source, else any one.
        $this->addSql('ALTER TABLE sylius_product_variant ADD fulfilment VARCHAR(20) DEFAULT \'unconfirmed\' NOT NULL, ADD distributor VARCHAR(50) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE sylius_product_variant v
            JOIN app_supplier_item s ON s.id = (
                SELECT s2.id FROM app_supplier_item s2 WHERE s2.variant_id = v.id
                ORDER BY s2.supplier = 'ors_nasco' DESC, s2.id LIMIT 1
            )
            SET v.distributor = s.supplier, v.fulfilment = s.fulfilment
            SQL);

        $this->addSql('ALTER TABLE app_product_import_state ADD supplier_sku VARCHAR(255) DEFAULT NULL');
        $this->addSql('UPDATE app_product_import_state st JOIN app_supplier_item s ON s.sku = st.product_code AND s.supplier = st.distributor SET st.supplier_sku = s.supplier_sku');
        $this->addSql('ALTER TABLE app_product_import_state DROP distributor');

        // Only one cost row per SKU fits the old table: keep the ORS one, else the first.
        $this->addSql('DELETE s FROM app_supplier_item s JOIN app_supplier_item keep ON keep.sku = s.sku AND keep.id <> s.id AND (keep.supplier = \'ors_nasco\' OR (s.supplier <> \'ors_nasco\' AND keep.id < s.id))');
        $this->addSql('ALTER TABLE app_supplier_item DROP FOREIGN KEY FK_483EEE5B3B69A9AF');
        $this->addSql('DROP INDEX IDX_483EEE5B3B69A9AF ON app_supplier_item');
        $this->addSql('DROP INDEX uniq_app_supplier_item_sku_supplier ON app_supplier_item');
        $this->addSql('ALTER TABLE app_supplier_item DROP supplier_sku, DROP fulfilment, DROP enabled, DROP stock, DROP stock_synced_at, DROP variant_id');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_483EEE5BF9038C4 ON app_supplier_item (sku)');
    }
}
