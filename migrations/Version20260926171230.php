<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260926171230 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE app_rfq_line_item_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE app_rfq_request_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE app_rfq_line_item (id INT NOT NULL, quantity INT NOT NULL, partNumber VARCHAR(100) DEFAULT NULL, description VARCHAR(500) NOT NULL, unitOfMeasure VARCHAR(50) DEFAULT NULL, rfqRequest_id INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_8D22E996C3B61895 ON app_rfq_line_item (rfqRequest_id)');
        $this->addSql('CREATE TABLE app_rfq_request (id INT NOT NULL, customer_id INT DEFAULT NULL, customerType VARCHAR(20) NOT NULL, fullName VARCHAR(255) NOT NULL, company VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, phone VARCHAR(50) NOT NULL, jobSite VARCHAR(255) DEFAULT NULL, requiredByDate DATE DEFAULT NULL, urgencyLevel VARCHAR(20) NOT NULL, deliveryAddress VARCHAR(255) DEFAULT NULL, additionalNotes TEXT DEFAULT NULL, status VARCHAR(20) NOT NULL, createdAt TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_85F06E489395C3F3 ON app_rfq_request (customer_id)');
        $this->addSql('COMMENT ON COLUMN app_rfq_request.requiredByDate IS \'(DC2Type:date_immutable)\'');
        $this->addSql('COMMENT ON COLUMN app_rfq_request.createdAt IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE app_rfq_line_item ADD CONSTRAINT FK_8D22E996C3B61895 FOREIGN KEY (rfqRequest_id) REFERENCES app_rfq_request (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE app_rfq_request ADD CONSTRAINT FK_85F06E489395C3F3 FOREIGN KEY (customer_id) REFERENCES sylius_customer (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('DROP INDEX idx_75ea56e016ba31db');
        $this->addSql('DROP INDEX idx_75ea56e0e3bd61ce');
        $this->addSql('DROP INDEX idx_75ea56e0fb7336f0');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP SEQUENCE app_rfq_line_item_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE app_rfq_request_id_seq CASCADE');
        $this->addSql('ALTER TABLE app_rfq_line_item DROP CONSTRAINT FK_8D22E996C3B61895');
        $this->addSql('ALTER TABLE app_rfq_request DROP CONSTRAINT FK_85F06E489395C3F3');
        $this->addSql('DROP TABLE app_rfq_line_item');
        $this->addSql('DROP TABLE app_rfq_request');
        $this->addSql('DROP INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750');
        $this->addSql('CREATE INDEX idx_75ea56e016ba31db ON messenger_messages (delivered_at)');
        $this->addSql('CREATE INDEX idx_75ea56e0e3bd61ce ON messenger_messages (available_at)');
        $this->addSql('CREATE INDEX idx_75ea56e0fb7336f0 ON messenger_messages (queue_name)');
    }
}
