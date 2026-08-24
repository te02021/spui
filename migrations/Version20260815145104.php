<?php

declare(strict_types=1);

namespace Shared\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rollup horario de telemetría y limpieza del índice redundante.
 *
 * Los reproductores publican una lectura por minuto, pero el CMS las consume
 * siempre agregadas por hora. telemetria_hora guarda ese resumen, lo que
 * permite retener el detalle crudo pocos días y el histórico por años a 1/60
 * del espacio.
 *
 * Además se elimina IDX_8C0CB1A5E7089003 de telemetria: es prefijo exacto de
 * idx_reproductor_registrado, así que no aporta ninguna capacidad de acceso,
 * ocupa ~23% del tamaño por fila, y el optimizador lo estaba eligiendo por
 * sobre el índice compuesto (con el consiguiente 'Using temporary' en los
 * GROUP BY de los gráficos). La FK FK_8C0CB1A5E7089003 sigue cubierta por el
 * índice compuesto, que empieza por la misma columna.
 */
final class Version20260815145104 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabla telemetria_hora (rollup horario) y baja del indice redundante en telemetria.';
    }

    public function up(Schema $schema): void
    {
        // Sin el índice de una sola columna que genera Doctrine por la FK:
        // uq_reproductor_hora ya empieza por reproductor_id y lo cubre.
        $this->addSql(<<<'SQL'
            CREATE TABLE telemetria_hora (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                reproductor_id INT UNSIGNED NOT NULL,
                hora DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                temp_prom NUMERIC(5, 2) NOT NULL,
                temp_max NUMERIC(5, 2) NOT NULL,
                ram_prom NUMERIC(5, 2) NOT NULL,
                ram_max NUMERIC(5, 2) NOT NULL,
                disco_min INT UNSIGNED NOT NULL,
                lecturas SMALLINT UNSIGNED NOT NULL,
                UNIQUE INDEX uq_reproductor_hora (reproductor_id, hora),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE telemetria_hora
                ADD CONSTRAINT FK_65E1A176E7089003
                FOREIGN KEY (reproductor_id) REFERENCES reproductor (id) ON DELETE CASCADE
            SQL);

        // Backfill: consolida la telemetría que ya está guardada, para no
        // perder el histórico al bajar la retención del crudo. Es el mismo
        // SQL que usa spui:telemetria:rollup.
        $this->addSql(<<<'SQL'
            INSERT INTO telemetria_hora
                (reproductor_id, hora, temp_prom, temp_max, ram_prom, ram_max, disco_min, lecturas)
            SELECT reproductor_id,
                   DATE_FORMAT(registrado_en, '%Y-%m-%d %H:00:00'),
                   AVG(temperatura_soc_celsius),
                   MAX(temperatura_soc_celsius),
                   AVG(uso_ram_porcentaje),
                   MAX(uso_ram_porcentaje),
                   MIN(espacio_disco_libre_mb),
                   COUNT(*)
              FROM telemetria
             GROUP BY reproductor_id, DATE_FORMAT(registrado_en, '%Y-%m-%d %H:00:00')
            ON DUPLICATE KEY UPDATE lecturas = VALUES(lecturas)
            SQL);

        // El índice redundante se borra DESPUÉS del backfill: mientras existe,
        // el GROUP BY de arriba puede aprovecharlo.
        $this->addSql('DROP INDEX IDX_8C0CB1A5E7089003 ON telemetria');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_8C0CB1A5E7089003 ON telemetria (reproductor_id)');
        $this->addSql('ALTER TABLE telemetria_hora DROP FOREIGN KEY FK_65E1A176E7089003');
        $this->addSql('DROP TABLE telemetria_hora');
    }
}
