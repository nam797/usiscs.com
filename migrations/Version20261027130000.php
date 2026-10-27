<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractMigration;

final class Version20261027130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cross-dock warehouse: expected/received/shipped-on fields on purchase orders, and each order\'s fulfilment route (MySQL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_purchase_order ADD expected_at DATE DEFAULT NULL, ADD received_at DATETIME DEFAULT NULL, ADD received_by VARCHAR(180) DEFAULT NULL, ADD forwarded_at DATETIME DEFAULT NULL, ADD forwarded_by VARCHAR(180) DEFAULT NULL, ADD forwarded_carrier VARCHAR(100) DEFAULT NULL, ADD forwarded_tracking VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE sylius_order ADD fulfilment_route VARCHAR(20) DEFAULT NULL');

        // Orders that already have purchase orders: the same rule as App\Distributor\FulfilmentRoute::of().
        $this->addSql(<<<'SQL'
            UPDATE sylius_order o SET o.fulfilment_route = (
                SELECT CASE
                    WHEN SUM(p.fulfilment = 'unconfirmed') > 0 THEN 'unconfirmed'
                    WHEN SUM(p.fulfilment = 'ors_direct') > 0 AND SUM(p.fulfilment = 'cross_dock') > 0 THEN 'mixed'
                    WHEN SUM(p.fulfilment = 'cross_dock') > 0 THEN 'cross_dock'
                    ELSE 'direct'
                END
                FROM app_purchase_order p WHERE p.order_id = o.id AND p.state <> 'cancelled'
            )
            WHERE EXISTS (SELECT 1 FROM app_purchase_order p2 WHERE p2.order_id = o.id AND p2.state <> 'cancelled')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sylius_order DROP fulfilment_route');
        $this->addSql('ALTER TABLE app_purchase_order DROP expected_at, DROP received_at, DROP received_by, DROP forwarded_at, DROP forwarded_by, DROP forwarded_carrier, DROP forwarded_tracking');
    }
}
