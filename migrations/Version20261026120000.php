<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractMigration;

final class Version20261026120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the contact message table (/contact submissions, kept for /backoffice) (MySQL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_contact_message (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(20) NOT NULL, createdAt DATETIME NOT NULL, fullName VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, topic VARCHAR(50) NOT NULL, message LONGTEXT NOT NULL, company VARCHAR(255) DEFAULT NULL, phone VARCHAR(50) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_contact_message');
    }
}
