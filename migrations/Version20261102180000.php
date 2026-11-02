<?php

declare(strict_types=1);

namespace Shared\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La hora de fin de un ítem de cronograma pasa a ser opcional (02/11/2026):
 * el Excel de horarios trae eventos especiales sin hora de fin ("-"), y
 * Mateo prefirió mostrarlos como "Desde las 18:00" antes que inventar una.
 * Sólo un evento puede quedar sin ella; lo controla el CMS.
 *
 * El down() falla si ya hay eventos sin hora de fin: completarlos antes.
 */
final class Version20261102180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cronograma_item.hora_fin opcional (eventos sin hora de fin).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cronograma_item CHANGE hora_fin hora_fin TIME DEFAULT NULL COMMENT \'(DC2Type:time_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cronograma_item CHANGE hora_fin hora_fin TIME NOT NULL COMMENT \'(DC2Type:time_immutable)\'');
    }
}
