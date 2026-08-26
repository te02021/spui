<?php

declare(strict_types=1);

namespace Shared\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260825115404 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tarea 1.3 (LWT): columna para el aviso de caída publicado por el broker cuando un reproductor se cae de golpe.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reproductor ADD lwt_offline_desde DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reproductor DROP lwt_offline_desde');
    }
}
