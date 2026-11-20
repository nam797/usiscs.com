<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractMigration;

/**
 * The 50 US states and DC as provinces of the United States, so checkout
 * asks for the state from a list instead of a free-text "Province". Adds
 * only what is missing, and nothing when the US isn't set up yet (a fresh
 * database before fixtures).
 */
final class Version20261120120000 extends AbstractMigration
{
    private const STATES = [
        ['AL', 'Alabama'],
        ['AK', 'Alaska'],
        ['AZ', 'Arizona'],
        ['AR', 'Arkansas'],
        ['CA', 'California'],
        ['CO', 'Colorado'],
        ['CT', 'Connecticut'],
        ['DE', 'Delaware'],
        ['DC', 'District of Columbia'],
        ['FL', 'Florida'],
        ['GA', 'Georgia'],
        ['HI', 'Hawaii'],
        ['ID', 'Idaho'],
        ['IL', 'Illinois'],
        ['IN', 'Indiana'],
        ['IA', 'Iowa'],
        ['KS', 'Kansas'],
        ['KY', 'Kentucky'],
        ['LA', 'Louisiana'],
        ['ME', 'Maine'],
        ['MD', 'Maryland'],
        ['MA', 'Massachusetts'],
        ['MI', 'Michigan'],
        ['MN', 'Minnesota'],
        ['MS', 'Mississippi'],
        ['MO', 'Missouri'],
        ['MT', 'Montana'],
        ['NE', 'Nebraska'],
        ['NV', 'Nevada'],
        ['NH', 'New Hampshire'],
        ['NJ', 'New Jersey'],
        ['NM', 'New Mexico'],
        ['NY', 'New York'],
        ['NC', 'North Carolina'],
        ['ND', 'North Dakota'],
        ['OH', 'Ohio'],
        ['OK', 'Oklahoma'],
        ['OR', 'Oregon'],
        ['PA', 'Pennsylvania'],
        ['RI', 'Rhode Island'],
        ['SC', 'South Carolina'],
        ['SD', 'South Dakota'],
        ['TN', 'Tennessee'],
        ['TX', 'Texas'],
        ['UT', 'Utah'],
        ['VT', 'Vermont'],
        ['VA', 'Virginia'],
        ['WA', 'Washington'],
        ['WV', 'West Virginia'],
        ['WI', 'Wisconsin'],
        ['WY', 'Wyoming'],
    ];

    public function getDescription(): string
    {
        return 'US states and DC as provinces of the United States (MySQL)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STATES as [$abbreviation, $name]) {
            $this->addSql(
                'INSERT INTO sylius_province (country_id, code, name, abbreviation)
                 SELECT c.id, ?, ?, ? FROM sylius_country c
                 WHERE c.code = \'US\' AND NOT EXISTS (SELECT 1 FROM sylius_province p WHERE p.code = ?)',
                ['US-'.$abbreviation, $name, $abbreviation, 'US-'.$abbreviation],
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::STATES as [$abbreviation]) {
            $this->addSql('DELETE FROM sylius_province WHERE code = ?', ['US-'.$abbreviation]);
        }
    }
}
