<?php

declare(strict_types=1);

namespace SPUI\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260702140456 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DELETE FROM telemetria');
        $this->addSql('ALTER TABLE telemetria ADD CONSTRAINT FK_8C0CB1A5E7089003 FOREIGN KEY (reproductor_id) REFERENCES reproductor (id)');
        $this->addSql('CREATE INDEX IDX_8C0CB1A5E7089003 ON telemetria (reproductor_id)');
        $this->addSql('CREATE INDEX idx_reproductor_registrado ON telemetria (reproductor_id, registrado_en)');
        $this->addSql('DELETE FROM programacion');
        $this->addSql('DELETE FROM pantalla');
        $this->addSql('DELETE FROM ubicacion');
        $this->addSql('ALTER TABLE ubicacion ADD edificio_id INT UNSIGNED NOT NULL, ADD sector VARCHAR(150) DEFAULT NULL, DROP edificio, DROP aula');
        $this->addSql('ALTER TABLE ubicacion ADD CONSTRAINT FK_DC158CB88A652BD6 FOREIGN KEY (edificio_id) REFERENCES edificio (id)');
        $this->addSql('CREATE INDEX IDX_DC158CB88A652BD6 ON ubicacion (edificio_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE telemetria DROP FOREIGN KEY FK_8C0CB1A5E7089003');
        $this->addSql('DROP INDEX IDX_8C0CB1A5E7089003 ON telemetria');
        $this->addSql('DROP INDEX idx_reproductor_registrado ON telemetria');
        $this->addSql('ALTER TABLE ubicacion DROP FOREIGN KEY FK_DC158CB88A652BD6');
        $this->addSql('DROP INDEX IDX_DC158CB88A652BD6 ON ubicacion');
        $this->addSql('ALTER TABLE ubicacion ADD edificio VARCHAR(100) NOT NULL, ADD aula VARCHAR(100) DEFAULT NULL, DROP edificio_id, DROP sector');
    }
}
