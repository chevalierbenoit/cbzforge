<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260821091538 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE Task ADD COLUMN payload CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__Task AS SELECT id, type, status, libraryId, path, error, createdAt, completedAt FROM Task');
        $this->addSql('DROP TABLE Task');
        $this->addSql('CREATE TABLE Task (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, type VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, libraryId INTEGER NOT NULL, path VARCHAR(1024) NOT NULL, error CLOB DEFAULT NULL, createdAt DATETIME NOT NULL, completedAt DATETIME DEFAULT NULL)');
        $this->addSql('INSERT INTO Task (id, type, status, libraryId, path, error, createdAt, completedAt) SELECT id, type, status, libraryId, path, error, createdAt, completedAt FROM __temp__Task');
        $this->addSql('DROP TABLE __temp__Task');
    }
}
