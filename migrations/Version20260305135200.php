<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260305135200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE fix');
        $this->addSql('CREATE TEMPORARY TABLE __temp__scan_job AS SELECT id, repo_url, status, created_at, finished_at, global_score, user_id FROM scan_job');
        $this->addSql('DROP TABLE scan_job');
        $this->addSql('CREATE TABLE scan_job (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, repo_url VARCHAR(200) NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, global_score INTEGER DEFAULT NULL, user_id INTEGER DEFAULT NULL, CONSTRAINT FK_8FFE2CAFA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO scan_job (id, repo_url, status, created_at, finished_at, global_score, user_id) SELECT id, repo_url, status, created_at, finished_at, global_score, user_id FROM __temp__scan_job');
        $this->addSql('DROP TABLE __temp__scan_job');
        $this->addSql('CREATE INDEX IDX_8FFE2CAFA76ED395 ON scan_job (user_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE fix (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, original_code CLOB DEFAULT NULL COLLATE "BINARY", fixed_code CLOB DEFAULT NULL COLLATE "BINARY", applied BOOLEAN NOT NULL, branch_name VARCHAR(255) DEFAULT NULL COLLATE "BINARY", vulnerability_id INTEGER NOT NULL, CONSTRAINT FK_59FA476072897D8B FOREIGN KEY (vulnerability_id) REFERENCES vulnerability (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_59FA476072897D8B ON fix (vulnerability_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__scan_job AS SELECT id, repo_url, status, created_at, finished_at, global_score, user_id FROM scan_job');
        $this->addSql('DROP TABLE scan_job');
        $this->addSql('CREATE TABLE scan_job (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, repo_url VARCHAR(200) NOT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, global_score INTEGER DEFAULT NULL, user_id INTEGER DEFAULT NULL, CONSTRAINT FK_8FFE2CAFA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO scan_job (id, repo_url, status, created_at, finished_at, global_score, user_id) SELECT id, repo_url, status, created_at, finished_at, global_score, user_id FROM __temp__scan_job');
        $this->addSql('DROP TABLE __temp__scan_job');
        $this->addSql('CREATE INDEX IDX_8FFE2CAFA76ED395 ON scan_job (user_id)');
    }
}
