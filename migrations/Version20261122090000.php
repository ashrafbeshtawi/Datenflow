<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Flag inquiries whose mails failed so the admin can resend them';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inquiry ADD mail_failed BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inquiry DROP mail_failed');
    }
}
