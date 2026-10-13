<?php

declare(strict_types=1);

namespace Shared\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Las pantallas pasan a tener sólo dos estados: activa o inactiva.
 *
 * El tercer valor, "mantenimiento", no hacía nada distinto (ningún estado
 * afectaba lo que mostraba la pantalla) y el botón del listado no tenía cómo
 * volver a él. Se eliminó de EstadoPantalla, así que las filas que lo tuvieran
 * tienen que pasar a inactiva antes de que Doctrine las lea: un valor que no
 * existe en el enum rompe la hidratación.
 *
 * Sólo datos: la columna ya es VARCHAR.
 */
final class Version20261007120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'pantalla.estado: "mantenimiento" pasa a "inactivo" (el estado se eliminó).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE pantalla SET estado = 'inactivo' WHERE estado = 'mantenimiento'");
    }

    public function down(Schema $schema): void
    {
        // No hay forma de saber cuáles estaban en mantenimiento: quedan inactivas.
    }
}
