<?php

declare(strict_types=1);

namespace SPUI\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260702141102 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DELETE FROM pantalla WHERE ubicacion_id IN (SELECT id FROM (SELECT id FROM ubicacion) t)');
        $this->addSql('DELETE FROM ubicacion');
        $this->addSql('ALTER TABLE ubicacion ADD CONSTRAINT FK_DC158CB88A652BD6 FOREIGN KEY (edificio_id) REFERENCES edificio (id)');
        $this->addSql('CREATE INDEX IDX_DC158CB88A652BD6 ON ubicacion (edificio_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ubicacion DROP FOREIGN KEY FK_DC158CB88A652BD6');
        $this->addSql('DROP INDEX IDX_DC158CB88A652BD6 ON ubicacion');
    }
}
