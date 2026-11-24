<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track whether an inquiry\'s mails went out, so the admin can resend failed ones';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inquiry ADD mail_sent BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inquiry DROP mail_sent');
    }
}
