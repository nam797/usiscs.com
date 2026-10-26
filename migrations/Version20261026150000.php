<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractMigration;

/**
 * Stripe is the only payment processor, so the Mollie, Adyen and PayPal
 * plugins (and the Refund plugin they pulled in) were removed. This drops
 * their tables and the columns they added to Sylius's own tables.
 *
 * Everything is checked against the live schema first: a database created
 * after the removal never had these tables, and MySQL 8.4 has no
 * "DROP COLUMN IF EXISTS".
 */
final class Version20261026150000 extends AbstractMigration
{
    private const TABLE_PREFIXES = ['mollie_', 'sylius_adyen_', 'sylius_paypal_plugin_', 'sylius_refund_'];

    private const COLUMNS = [
        'sylius_order' => ['mollie_payment_id', 'qr_code', 'recurring_sequence_index', 'abandoned_email'],
        'sylius_product' => ['product_type_id'],
        'sylius_product_variant' => ['recurring', 'recurring_times', 'recurring_interval', 'commodity_code'],
        'sylius_admin_user' => ['mollie_onboarding_completed'],
    ];

    private const MIGRATION_NAMESPACES = ['Sylius\\MolliePlugin\\', 'Sylius\\AdyenPlugin\\', 'Sylius\\PayPalPlugin\\', 'Sylius\\RefundPlugin\\'];

    public function getDescription(): string
    {
        return 'Remove the Mollie, Adyen, PayPal and Refund plugin tables and columns (Stripe only) (MySQL)';
    }

    public function up(Schema $schema): void
    {
        // Sylius columns first: sylius_product.product_type_id points at a Mollie table.
        foreach (self::COLUMNS as $tableName => $columns) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }
            $table = $schema->getTable($tableName);
            $columns = array_values(array_filter($columns, static fn (string $column): bool => $table->hasColumn($column)));
            if ([] === $columns) {
                continue;
            }

            foreach ($table->getForeignKeys() as $foreignKey) {
                if ([] !== array_intersect($foreignKey->getLocalColumns(), $columns)) {
                    $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $tableName, $foreignKey->getName()));
                }
            }
            foreach ($table->getIndexes() as $index) {
                if (!$index->isPrimary() && [] === array_diff($index->getColumns(), $columns)) {
                    $this->addSql(sprintf('DROP INDEX %s ON %s', $index->getName(), $tableName));
                }
            }
            $this->addSql(sprintf('ALTER TABLE %s %s', $tableName, implode(', ', array_map(static fn (string $column): string => 'DROP '.$column, $columns))));
        }

        $tables = [];
        foreach ($schema->getTables() as $table) {
            foreach (self::TABLE_PREFIXES as $prefix) {
                if (str_starts_with($table->getName(), $prefix)) {
                    $tables[] = $table->getName();
                }
            }
        }
        if ([] !== $tables) {
            // The plugin tables reference each other; drop them as one set.
            $this->addSql('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($tables as $table) {
                $this->addSql(sprintf('DROP TABLE %s', $table));
            }
            $this->addSql('SET FOREIGN_KEY_CHECKS = 1');
        }

        // Their own migrations are gone from the code; forget that they ran.
        foreach (self::MIGRATION_NAMESPACES as $namespace) {
            $this->addSql('DELETE FROM sylius_migrations WHERE version LIKE ?', [addcslashes($namespace, '\\%_').'%']);
        }
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Reinstall the plugins with composer to get their tables back.');
    }
}
