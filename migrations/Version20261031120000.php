<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractMigration;

final class Version20261031120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sent-email log (app_sent_email): every email sent, and whether Postmark delivered, bounced or saw it opened (MySQL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_sent_email (id INT AUTO_INCREMENT NOT NULL, message_id VARCHAR(255) DEFAULT NULL, type VARCHAR(64) DEFAULT NULL, recipients VARCHAR(1000) NOT NULL, original_recipients VARCHAR(1000) DEFAULT NULL, subject VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, status_detail LONGTEXT DEFAULT NULL, sent_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, opened_at DATETIME DEFAULT NULL, INDEX idx_app_sent_email_message_id (message_id), INDEX idx_app_sent_email_sent_at (sent_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_sent_email');
    }
}
