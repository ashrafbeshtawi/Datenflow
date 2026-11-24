<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep karriere CVs in the database, deleted together with their inquiry';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inquiry_cv (inquiry_id INT NOT NULL, file_name VARCHAR(255) NOT NULL, content BYTEA NOT NULL, PRIMARY KEY (inquiry_id))');
        $this->addSql('ALTER TABLE inquiry_cv ADD CONSTRAINT FK_INQUIRY_CV_INQUIRY FOREIGN KEY (inquiry_id) REFERENCES inquiry (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE inquiry_cv');
    }
}
