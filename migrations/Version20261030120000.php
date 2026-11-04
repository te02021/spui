<?php

declare(strict_types=1);

namespace Shared\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Se sacan IP, MAC y resolución de la pantalla (30/10/2026): se cargaban a
 * mano, nada los usaba (la Pi reproduce a pantalla completa en lo que detecta
 * por HDMI e informa su propia red) y se desactualizaban solos. El down() los
 * vuelve a crear vacíos, con la resolución por defecto: los valores cargados
 * no se recuperan.
 */
final class Version20261030120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'pantalla: se quitan ip_address, mac_address, resolucion_ancho y resolucion_alto.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pantalla DROP ip_address, DROP mac_address, DROP resolucion_ancho, DROP resolucion_alto');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pantalla ADD ip_address VARCHAR(45) DEFAULT NULL, ADD mac_address VARCHAR(17) DEFAULT NULL, '
            . 'ADD resolucion_ancho SMALLINT UNSIGNED NOT NULL DEFAULT 1920, ADD resolucion_alto SMALLINT UNSIGNED NOT NULL DEFAULT 1080');
    }
}
