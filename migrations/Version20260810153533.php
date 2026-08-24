<?php

declare(strict_types=1);

namespace Shared\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Alertas dirigidas: tabla puente alerta_emergencia ↔ pantalla.
 *
 * Hasta acá toda alerta se emitía a todas las pantallas. Con esta tabla se
 * puede elegir a cuáles va. Las alertas ya cargadas no tienen filas acá, así
 * que siguen siendo globales (colección vacía = todas) y no cambian de
 * comportamiento.
 */
final class Version20260810153533 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabla puente alerta_pantalla para alertas dirigidas a pantallas específicas.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE alerta_pantalla (alerta_emergencia_id INT UNSIGNED NOT NULL, pantalla_id INT UNSIGNED NOT NULL, INDEX IDX_2311CF5F101C982 (alerta_emergencia_id), INDEX IDX_2311CF5394D775 (pantalla_id), PRIMARY KEY(alerta_emergencia_id, pantalla_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE alerta_pantalla ADD CONSTRAINT FK_2311CF5F101C982 FOREIGN KEY (alerta_emergencia_id) REFERENCES alerta_emergencia (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE alerta_pantalla ADD CONSTRAINT FK_2311CF5394D775 FOREIGN KEY (pantalla_id) REFERENCES pantalla (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alerta_pantalla DROP FOREIGN KEY FK_2311CF5F101C982');
        $this->addSql('ALTER TABLE alerta_pantalla DROP FOREIGN KEY FK_2311CF5394D775');
        $this->addSql('DROP TABLE alerta_pantalla');
    }
}
