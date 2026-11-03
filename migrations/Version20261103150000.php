<?php

declare(strict_types=1);

namespace Shared\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Uso de CPU en la telemetría (03/11/2026, pedido de Mateo): la Pi lo manda
 * desde ahora como promedio de cada minuto. Las lecturas anteriores quedan
 * sin el dato (NULL), igual que las de una Pi sin actualizar.
 *
 * El resumen por hora guarda además el promedio de la latencia de red: el
 * gráfico del Dashboard puede mostrarla en 30 días, y la telemetría cruda
 * se purga a los 7.
 */
final class Version20261103150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Telemetría: uso de CPU (crudo y por hora) y latencia promedio por hora.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telemetria ADD uso_cpu_porcentaje NUMERIC(5, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE telemetria_hora ADD cpu_prom NUMERIC(5, 2) DEFAULT NULL, ADD cpu_max NUMERIC(5, 2) DEFAULT NULL, ADD lat_prom NUMERIC(7, 1) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telemetria_hora DROP cpu_prom, DROP cpu_max, DROP lat_prom');
        $this->addSql('ALTER TABLE telemetria DROP uso_cpu_porcentaje');
    }
}
