<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260302154823 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE fix (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, original_code CLOB DEFAULT NULL, fixed_code CLOB DEFAULT NULL, applied BOOLEAN NOT NULL, branch_name VARCHAR(255) DEFAULT NULL, vulnerability VARCHAR(255) NOT NULL)');
        $this->addSql('CREATE TABLE scan_job (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, repo_url VARCHAR(200) NOT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, global_score INTEGER DEFAULT NULL)');
        $this->addSql('CREATE TABLE vulnerability (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, tool VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL, file_path VARCHAR(255) NOT NULL, line_number INTEGER DEFAULT NULL, severity VARCHAR(255) NOT NULL, owasp_category VARCHAR(255) DEFAULT NULL, description CLOB DEFAULT NULL, raw_data CLOB DEFAULT NULL, scanjob CHAR(36) NOT NULL)');
        $this->addSql('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE fix');
        $this->addSql('DROP TABLE scan_job');
        $this->addSql('DROP TABLE vulnerability');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
