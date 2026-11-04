<?php

declare(strict_types=1);

namespace Shared\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Menos estados que entender en el CMS (29/10/2026, decisión de Mateo).
 *
 * - Contenido: sin borrador ni publicar. Todo contenido está disponible apenas
 *   se crea; los que estaban en borrador o publicados pasan a 'disponible'.
 *   Archivado queda, y ahora saca el contenido de las pantallas.
 * - Playlist: sin Activa/Inactiva. No frenaba la reproducción (el sync nunca
 *   lo miraba) y la desactivada se seguía ofreciendo en Pantallas y
 *   Programación: se borra la columna.
 */
final class Version20261029120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contenido: borrador/publicado pasan a disponible. Playlist: se borra la columna activo.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE contenido SET estado = 'disponible' WHERE estado IN ('borrador', 'publicado')");
        $this->addSql('ALTER TABLE playlist DROP activo');
    }

    public function down(Schema $schema): void
    {
        // No se sabe cuáles estaban en borrador: vuelven todos como publicados.
        $this->addSql("UPDATE contenido SET estado = 'publicado' WHERE estado = 'disponible'");
        $this->addSql('ALTER TABLE playlist ADD activo TINYINT(1) NOT NULL DEFAULT 1');
    }
}
