<?php

declare(strict_types=1);

namespace Shared\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tamaño total del disco de cada reproductor, para el indicador circular de
 * disco de la telemetría (29/10/2026). Lo manda la Pi con su telemetría; null
 * hasta la primera lectura de un cliente que lo incluya.
 */
final class Version20261029150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'reproductor.disco_total_mb (indicador de disco de la telemetría).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reproductor ADD disco_total_mb INT UNSIGNED DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reproductor DROP disco_total_mb');
    }
}
