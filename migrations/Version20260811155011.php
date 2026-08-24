<?php

declare(strict_types=1);

namespace Shared\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Elimina el override de duración por ítem de playlist.
 *
 * La duración de un contenido se definía en dos lugares: en el propio contenido
 * y, opcionalmente, pisada en cada playlist que lo usara. Dos fuentes de verdad
 * para el mismo dato. A partir de acá la única es la del contenido, y la
 * duración de una playlist es la suma de las de sus ítems.
 *
 * OJO: el down() restituye la columna pero NO los datos — los overrides que
 * hubiera cargados se pierden al aplicar esta migración.
 */
final class Version20260811155011 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Elimina duracion_override_seg: la duración es la del contenido.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE playlist_item DROP duracion_override_seg');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE playlist_item ADD duracion_override_seg SMALLINT UNSIGNED DEFAULT NULL');
    }
}
