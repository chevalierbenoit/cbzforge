<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The BDGest scraper was renamed Bedetheque (it reads bedetheque.com). Its enable flag is a setting
 * keyed by the scraper name, so the key moves with it and a disabled source stays disabled.
 */
final class Version20261018100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename the scraper setting key scraper.BDGest.enabled to scraper.Bedetheque.enabled';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE Setting SET key = 'scraper.Bedetheque.enabled' WHERE key = 'scraper.BDGest.enabled'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE Setting SET key = 'scraper.BDGest.enabled' WHERE key = 'scraper.Bedetheque.enabled'");
    }
}
