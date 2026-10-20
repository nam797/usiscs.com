<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractMigration;

final class Version20261020002458 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the RFQ request and line item tables (MySQL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_rfq_line_item (id INT AUTO_INCREMENT NOT NULL, quantity INT NOT NULL, partNumber VARCHAR(100) DEFAULT NULL, description VARCHAR(500) NOT NULL, unitOfMeasure VARCHAR(50) DEFAULT NULL, rfqRequest_id INT NOT NULL, INDEX IDX_8D22E996C3B61895 (rfqRequest_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE app_rfq_request (id INT AUTO_INCREMENT NOT NULL, customer_id INT DEFAULT NULL, customerType VARCHAR(20) NOT NULL, fullName VARCHAR(255) NOT NULL, company VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, phone VARCHAR(50) NOT NULL, jobSite VARCHAR(255) DEFAULT NULL, requiredByDate DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\', urgencyLevel VARCHAR(20) NOT NULL, deliveryAddress VARCHAR(255) DEFAULT NULL, additionalNotes LONGTEXT DEFAULT NULL, status VARCHAR(20) NOT NULL, createdAt DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_85F06E489395C3F3 (customer_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE app_rfq_line_item ADD CONSTRAINT FK_8D22E996C3B61895 FOREIGN KEY (rfqRequest_id) REFERENCES app_rfq_request (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE app_rfq_request ADD CONSTRAINT FK_85F06E489395C3F3 FOREIGN KEY (customer_id) REFERENCES sylius_customer (id) ON DELETE SET NULL');
        $this->addSql('DROP INDEX IDX_75EA56E0FB7336F0 ON messenger_messages');
        $this->addSql('DROP INDEX IDX_75EA56E0E3BD61CE ON messenger_messages');
        $this->addSql('DROP INDEX IDX_75EA56E016BA31DB ON messenger_messages');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_rfq_line_item DROP FOREIGN KEY FK_8D22E996C3B61895');
        $this->addSql('ALTER TABLE app_rfq_request DROP FOREIGN KEY FK_85F06E489395C3F3');
        $this->addSql('DROP TABLE app_rfq_line_item');
        $this->addSql('DROP TABLE app_rfq_request');
        $this->addSql('DROP INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0 ON messenger_messages (queue_name)');
        $this->addSql('CREATE INDEX IDX_75EA56E0E3BD61CE ON messenger_messages (available_at)');
        $this->addSql('CREATE INDEX IDX_75EA56E016BA31DB ON messenger_messages (delivered_at)');
    }
}
