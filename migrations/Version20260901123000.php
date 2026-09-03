<?php

declare(strict_types=1);

namespace Shared\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Limpia los códigos QR que no pertenecen a ningún contenido.
 *
 * Los códigos QR dejaron de ser una sección propia del CMS: ahora se crean y se
 * editan desde el contenido que los muestra, que es el único dueño de su código.
 * Los que quedaron sueltos de la etapa anterior —creados en /spui/qr y nunca
 * asociados a un contenido— ya no se pueden ver ni administrar desde ninguna
 * pantalla, así que se van.
 *
 * No toca ningún código que sí esté asociado: la condición mira
 * contenido.codigo_qr_id. Tampoco hace falta desarmar ninguna FK — la de
 * contenido es ON DELETE SET NULL y acá no se borra nada que esté referenciado.
 */
final class Version20260901123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Elimina los códigos QR huérfanos (sin contenido asociado), ya inaccesibles desde el CMS.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM codigo_qr
             WHERE id NOT IN (
                   SELECT codigo_qr_id FROM (
                          SELECT codigo_qr_id FROM contenido WHERE codigo_qr_id IS NOT NULL
                   ) AS referenciados
             )
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Los códigos borrados no se pueden recuperar (se pierde su url_destino
        // y su contador de escaneos). No hay vuelta atrás posible acá.
        $this->throwIrreversibleMigrationException(
            'No se pueden restaurar los códigos QR huérfanos eliminados.',
        );
    }
}
