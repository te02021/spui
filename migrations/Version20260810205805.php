<?php

declare(strict_types=1);

namespace Shared\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Diagnóstico del reproductor: problemas que el propio cliente Pi detecta y
 * reporta en cada heartbeat (por ejemplo, que no encuentra el entorno gráfico
 * y por lo tanto no está mostrando nada en pantalla).
 *
 * Sirve para que esos fallos se vean en el panel del CMS en vez de quedar
 * únicamente en el journal de la Raspberry Pi.
 */
final class Version20260810205805 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Campos de diagnóstico del reproductor (problemas reportados por el cliente Pi).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reproductor ADD diagnostico JSON DEFAULT NULL, ADD diagnostico_en DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reproductor DROP diagnostico, DROP diagnostico_en');
    }
}
