<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018120220 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE User (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, username VARCHAR(64) NOT NULL, password VARCHAR(255) NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2DA17977F85E0677 ON User (username)');
        // Installations that already have a library skip the onboarding assistant.
        $this->addSql('INSERT INTO Setting ("key", value) SELECT \'onboarding.done\', \'1\' WHERE EXISTS (SELECT 1 FROM Library)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DELETE FROM Setting WHERE "key" = \'onboarding.done\'');
        $this->addSql('DROP TABLE User');
    }
}
