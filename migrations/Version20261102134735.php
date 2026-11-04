<?php

declare(strict_types=1);

namespace Shared\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Plantillas de pantalla y cronograma semanal (02/11/2026).
 *
 * contenido: plantilla (institucional | ninguna), tema (automatico | claro |
 * oscuro) y marca de agua; para los cronogramas, origen (propio | edificio de
 * la pantalla), edificio, vigencia y segundos por página.
 * cronograma_item: tipo (clase | actividad | evento), fecha del evento y los
 * datos del Excel de horarios (carrera, año, docente).
 *
 * Los contenidos que ya existen toman lo mismo que tendrían al crearlos hoy:
 * imagen y video sin plantilla, el resto institucional; los cronogramas,
 * propios y con 10 s por página.
 */
final class Version20261102134735 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'contenido: plantilla, tema, marca de agua y datos de cronograma; cronograma_item: tipo, fecha, carrera, año y docente.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contenido ADD cronograma_edificio_id INT UNSIGNED DEFAULT NULL, ADD plantilla VARCHAR(20) DEFAULT \'institucional\' NOT NULL, ADD tema VARCHAR(20) DEFAULT \'automatico\' NOT NULL, ADD marca_agua TINYINT(1) DEFAULT 0 NOT NULL, ADD cronograma_origen VARCHAR(20) DEFAULT NULL, ADD vigencia_desde DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\', ADD vigencia_hasta DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\', ADD segundos_por_pagina SMALLINT UNSIGNED DEFAULT NULL');
        $this->addSql('ALTER TABLE contenido ADD CONSTRAINT FK_D0A7397F156C3668 FOREIGN KEY (cronograma_edificio_id) REFERENCES edificio (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_D0A7397F156C3668 ON contenido (cronograma_edificio_id)');
        $this->addSql('ALTER TABLE cronograma_item ADD tipo VARCHAR(20) DEFAULT \'clase\' NOT NULL, ADD fecha DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\', ADD carrera VARCHAR(60) DEFAULT NULL, ADD anio VARCHAR(40) DEFAULT NULL, ADD docente VARCHAR(255) DEFAULT NULL');

        $this->addSql("UPDATE contenido SET plantilla = 'ninguna' WHERE tipo IN ('imagen', 'video')");
        $this->addSql("UPDATE contenido SET cronograma_origen = 'propio', segundos_por_pagina = 10 WHERE tipo = 'cronograma'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contenido DROP FOREIGN KEY FK_D0A7397F156C3668');
        $this->addSql('DROP INDEX IDX_D0A7397F156C3668 ON contenido');
        $this->addSql('ALTER TABLE contenido DROP cronograma_edificio_id, DROP plantilla, DROP tema, DROP marca_agua, DROP cronograma_origen, DROP vigencia_desde, DROP vigencia_hasta, DROP segundos_por_pagina');
        $this->addSql('ALTER TABLE cronograma_item DROP tipo, DROP fecha, DROP carrera, DROP anio, DROP docente');
    }
}
