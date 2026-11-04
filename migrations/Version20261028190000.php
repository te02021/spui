<?php

declare(strict_types=1);

namespace Shared\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Se saca el tipo de contenido YouTube (28/10/2026): VLC no abre el link, y
 * YouTube frena las descargas desde la Pi ("confirmá que no sos un bot"). Un
 * video de YouTube se baja desde la PC y se sube como video.
 *
 * Los contenidos de tipo 'youtube' que queden se borran, con sus ítems en las
 * playlists: TipoContenido ya no tiene ese caso y Doctrine no podría leerlos.
 * Las alertas no pueden tener un YouTube como contenido adjunto (sólo imagen o
 * video), pero se desenganchan igual por las dudas.
 */
final class Version20261028190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Se saca el tipo de contenido youtube: se borran los que haya y sus ítems de playlist.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM playlist_item WHERE contenido_id IN (SELECT id FROM contenido WHERE tipo = 'youtube')");
        $this->addSql("UPDATE alerta_emergencia SET contenido_id = NULL WHERE contenido_id IN (SELECT id FROM contenido WHERE tipo = 'youtube')");
        $this->addSql("DELETE FROM contenido WHERE tipo = 'youtube'");
    }

    public function down(Schema $schema): void
    {
        // Los contenidos borrados no se pueden recuperar: no hay nada que deshacer en el esquema.
    }
}
