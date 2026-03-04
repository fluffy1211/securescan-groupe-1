<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260304133448 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout de la relation user (nullable) sur scan_job pour associer un scan à un utilisateur connecté';
    }

    public function up(Schema $schema): void
    {
        // SQLite ne supporte pas ALTER COLUMN avec FK — on reconstruit la table
        $this->addSql('CREATE TEMPORARY TABLE __temp__scan_job AS SELECT id, repo_url, status, created_at, finished_at, global_score FROM scan_job');
        $this->addSql('DROP TABLE scan_job');
        $this->addSql('CREATE TABLE scan_job (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            user_id INTEGER DEFAULT NULL,
            repo_url VARCHAR(200) NOT NULL,
            status VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL,
            finished_at DATETIME DEFAULT NULL,
            global_score INTEGER DEFAULT NULL,
            CONSTRAINT FK_8FFE2CAFA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('INSERT INTO scan_job (id, repo_url, status, created_at, finished_at, global_score) SELECT id, repo_url, status, created_at, finished_at, global_score FROM __temp__scan_job');
        $this->addSql('DROP TABLE __temp__scan_job');
        $this->addSql('CREATE INDEX IDX_8FFE2CAFA76ED395 ON scan_job (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__scan_job AS SELECT id, repo_url, status, created_at, finished_at, global_score FROM scan_job');
        $this->addSql('DROP TABLE scan_job');
        $this->addSql('CREATE TABLE scan_job (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            repo_url VARCHAR(200) NOT NULL,
            status VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL,
            finished_at DATETIME DEFAULT NULL,
            global_score INTEGER DEFAULT NULL
        )');
        $this->addSql('INSERT INTO scan_job (id, repo_url, status, created_at, finished_at, global_score) SELECT id, repo_url, status, created_at, finished_at, global_score FROM __temp__scan_job');
        $this->addSql('DROP TABLE __temp__scan_job');
    }
}
