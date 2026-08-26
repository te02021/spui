<?php

declare(strict_types=1);

namespace Shared\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260825130041 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tarea 1.4 (diagnóstico de auth): guardar el hash de la API key anterior a una regeneración, vigente 48h.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reproductor ADD api_key_hash_anterior VARCHAR(64) DEFAULT NULL, ADD api_key_hash_anterior_vence_en DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reproductor DROP api_key_hash_anterior, DROP api_key_hash_anterior_vence_en');
    }
}
