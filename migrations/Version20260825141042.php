<?php

declare(strict_types=1);

namespace Shared\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260825141042 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sonido personalizado opcional para alertas de emergencia (tarea alerta sonora).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alerta_emergencia ADD sonido_archivo VARCHAR(255) DEFAULT NULL, ADD sonido_hash_archivo VARCHAR(64) DEFAULT NULL, ADD sonido_nombre_original VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alerta_emergencia DROP sonido_archivo, DROP sonido_hash_archivo, DROP sonido_nombre_original');
    }
}
