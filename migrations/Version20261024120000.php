<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractMigration;

final class Version20261024120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Category slugs without the root taxon prefix ("products/safety-ppe" -> "safety-ppe") for root-level category URLs (MySQL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE sylius_taxon_translation tt
            INNER JOIN sylius_taxon t ON t.id = tt.translatable_id
            INNER JOIN sylius_taxon_translation rt ON rt.translatable_id = t.tree_root AND rt.locale = tt.locale
            SET tt.slug = SUBSTRING(tt.slug, CHAR_LENGTH(rt.slug) + 2)
            WHERE t.parent_id IS NOT NULL AND tt.slug LIKE CONCAT(rt.slug, '/%')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE sylius_taxon_translation tt
            INNER JOIN sylius_taxon t ON t.id = tt.translatable_id
            INNER JOIN sylius_taxon_translation rt ON rt.translatable_id = t.tree_root AND rt.locale = tt.locale
            SET tt.slug = CONCAT(rt.slug, '/', tt.slug)
            WHERE t.parent_id IS NOT NULL AND tt.slug NOT LIKE CONCAT(rt.slug, '/%')
            SQL);
    }
}
