<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260303090852 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('CREATE TEMPORARY TABLE __temp__fix AS SELECT id, original_code, fixed_code, applied, branch_name FROM fix');
        $this->addSql('DROP TABLE fix');
        $this->addSql('CREATE TABLE fix (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, original_code CLOB DEFAULT NULL, fixed_code CLOB DEFAULT NULL, applied BOOLEAN NOT NULL, branch_name VARCHAR(255) DEFAULT NULL, vulnerability_id INTEGER NOT NULL, CONSTRAINT FK_59FA476072897D8B FOREIGN KEY (vulnerability_id) REFERENCES vulnerability (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO fix (id, original_code, fixed_code, applied, branch_name) SELECT id, original_code, fixed_code, applied, branch_name FROM __temp__fix');
        $this->addSql('DROP TABLE __temp__fix');
        $this->addSql('CREATE INDEX IDX_59FA476072897D8B ON fix (vulnerability_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__vulnerability AS SELECT id, tool, title, file_path, line_number, severity, owasp_category, description, raw_data FROM vulnerability');
        $this->addSql('DROP TABLE vulnerability');
        $this->addSql('CREATE TABLE vulnerability (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, tool VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL, file_path VARCHAR(255) NOT NULL, line_number INTEGER DEFAULT NULL, severity VARCHAR(255) NOT NULL, owasp_category VARCHAR(255) DEFAULT NULL, description CLOB DEFAULT NULL, raw_data CLOB DEFAULT NULL, scan_job_id INTEGER NOT NULL, CONSTRAINT FK_6C4E4047D7516B57 FOREIGN KEY (scan_job_id) REFERENCES scan_job (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO vulnerability (id, tool, title, file_path, line_number, severity, owasp_category, description, raw_data) SELECT id, tool, title, file_path, line_number, severity, owasp_category, description, raw_data FROM __temp__vulnerability');
        $this->addSql('DROP TABLE __temp__vulnerability');
        $this->addSql('CREATE INDEX IDX_6C4E4047D7516B57 ON vulnerability (scan_job_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL COLLATE "BINARY", headers CLOB NOT NULL COLLATE "BINARY", queue_name VARCHAR(190) NOT NULL COLLATE "BINARY", created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__fix AS SELECT id, original_code, fixed_code, applied, branch_name FROM fix');
        $this->addSql('DROP TABLE fix');
        $this->addSql('CREATE TABLE fix (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, original_code CLOB DEFAULT NULL, fixed_code CLOB DEFAULT NULL, applied BOOLEAN NOT NULL, branch_name VARCHAR(255) DEFAULT NULL, vulnerability VARCHAR(255) NOT NULL)');
        $this->addSql('INSERT INTO fix (id, original_code, fixed_code, applied, branch_name) SELECT id, original_code, fixed_code, applied, branch_name FROM __temp__fix');
        $this->addSql('DROP TABLE __temp__fix');
        $this->addSql('CREATE TEMPORARY TABLE __temp__vulnerability AS SELECT id, tool, title, file_path, line_number, severity, owasp_category, description, raw_data FROM vulnerability');
        $this->addSql('DROP TABLE vulnerability');
        $this->addSql('CREATE TABLE vulnerability (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, tool VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL, file_path VARCHAR(255) NOT NULL, line_number INTEGER DEFAULT NULL, severity VARCHAR(255) NOT NULL, owasp_category VARCHAR(255) DEFAULT NULL, description CLOB DEFAULT NULL, raw_data CLOB DEFAULT NULL, scanjob CHAR(36) NOT NULL)');
        $this->addSql('INSERT INTO vulnerability (id, tool, title, file_path, line_number, severity, owasp_category, description, raw_data) SELECT id, tool, title, file_path, line_number, severity, owasp_category, description, raw_data FROM __temp__vulnerability');
        $this->addSql('DROP TABLE __temp__vulnerability');
    }
}
