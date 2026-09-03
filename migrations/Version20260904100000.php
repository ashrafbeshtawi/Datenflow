<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'One availability rule per weekday: dedupe, then enforce with a unique index';
    }

    public function up(Schema $schema): void
    {
        // The old UI allowed several rules per weekday — keep the oldest per day.
        $this->addSql('DELETE FROM availability_rule a USING availability_rule b WHERE a.weekday = b.weekday AND a.id > b.id');
        $this->addSql('CREATE UNIQUE INDEX uniq_availability_weekday ON availability_rule (weekday)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_availability_weekday');
    }
}
