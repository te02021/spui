<?php

declare(strict_types=1);

namespace Shared\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sync visible y configurable por reproductor: cuándo sincronizó por última vez
 * y cada cuántos minutos lo hace por su cuenta (5 por defecto, como hasta ahora).
 */
final class Version20261010185937 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'reproductor: último sync e intervalo de sync en minutos (5 por defecto).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reproductor ADD ultimo_sync DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD intervalo_sync_min INT UNSIGNED DEFAULT 5 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reproductor DROP ultimo_sync, DROP intervalo_sync_min');
    }
}
