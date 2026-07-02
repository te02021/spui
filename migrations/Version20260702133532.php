<?php

declare(strict_types=1);

namespace SPUI\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260702133532 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE telemetria DROP FOREIGN KEY FK_8C0CB1A529B07FB3');
        $this->addSql('CREATE TABLE cronograma_item (id INT UNSIGNED AUTO_INCREMENT NOT NULL, contenido_id INT UNSIGNED NOT NULL, nombre VARCHAR(200) NOT NULL, aula VARCHAR(100) DEFAULT NULL, hora_inicio TIME NOT NULL COMMENT \'(DC2Type:time_immutable)\', hora_fin TIME NOT NULL COMMENT \'(DC2Type:time_immutable)\', dias_semana SMALLINT UNSIGNED NOT NULL, activo TINYINT(1) NOT NULL, orden SMALLINT UNSIGNED NOT NULL, INDEX IDX_F8A78EAB7FDA517C (contenido_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE edificio (id INT UNSIGNED AUTO_INCREMENT NOT NULL, nombre VARCHAR(150) NOT NULL, descripcion LONGTEXT DEFAULT NULL, activo TINYINT(1) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE reproductor (id INT UNSIGNED AUTO_INCREMENT NOT NULL, hostname VARCHAR(100) NOT NULL, version_firmware VARCHAR(50) DEFAULT NULL, api_key_hash VARCHAR(64) NOT NULL, ultimo_heartbeat DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', estado_conexion VARCHAR(255) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE cronograma_item ADD CONSTRAINT FK_F8A78EAB7FDA517C FOREIGN KEY (contenido_id) REFERENCES contenido (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE nodo DROP FOREIGN KEY FK_65AA015B394D775');
        $this->addSql('DROP TABLE nodo');
        $this->addSql('ALTER TABLE contenido CHANGE duracion_segundos duracion_segundos SMALLINT UNSIGNED DEFAULT NULL');
        $this->addSql('DROP INDEX UNIQ_EBB5F6CAB728E969 ON pantalla');
        $this->addSql('ALTER TABLE pantalla ADD reproductor_id INT UNSIGNED DEFAULT NULL, ADD playlist_fallback_id INT UNSIGNED DEFAULT NULL, CHANGE mac_address mac_address VARCHAR(17) DEFAULT NULL');
        $this->addSql('ALTER TABLE pantalla ADD CONSTRAINT FK_EBB5F6CAE7089003 FOREIGN KEY (reproductor_id) REFERENCES reproductor (id)');
        $this->addSql('ALTER TABLE pantalla ADD CONSTRAINT FK_EBB5F6CA8129233C FOREIGN KEY (playlist_fallback_id) REFERENCES playlist (id)');
        $this->addSql('CREATE INDEX IDX_EBB5F6CAE7089003 ON pantalla (reproductor_id)');
        $this->addSql('CREATE INDEX IDX_EBB5F6CA8129233C ON pantalla (playlist_fallback_id)');
        $this->addSql('ALTER TABLE programacion ADD edificio_id INT UNSIGNED DEFAULT NULL, ADD repetir_semanal TINYINT(1) NOT NULL');
        $this->addSql('ALTER TABLE programacion ADD CONSTRAINT FK_14491F898A652BD6 FOREIGN KEY (edificio_id) REFERENCES edificio (id)');
        $this->addSql('CREATE INDEX IDX_14491F898A652BD6 ON programacion (edificio_id)');
        $this->addSql('DELETE FROM telemetria');
        $this->addSql('DROP INDEX IDX_8C0CB1A529B07FB3 ON telemetria');
        $this->addSql('DROP INDEX idx_nodo_registrado ON telemetria');
        $this->addSql('ALTER TABLE telemetria CHANGE nodo_id reproductor_id INT UNSIGNED NOT NULL');
        $this->addSql('ALTER TABLE telemetria ADD CONSTRAINT FK_8C0CB1A5E7089003 FOREIGN KEY (reproductor_id) REFERENCES reproductor (id)');
        $this->addSql('CREATE INDEX IDX_8C0CB1A5E7089003 ON telemetria (reproductor_id)');
        $this->addSql('CREATE INDEX idx_reproductor_registrado ON telemetria (reproductor_id, registrado_en)');
        $this->addSql('ALTER TABLE ubicacion ADD edificio_id INT UNSIGNED NOT NULL, ADD sector VARCHAR(150) DEFAULT NULL, DROP edificio, DROP aula');
        $this->addSql('ALTER TABLE ubicacion ADD CONSTRAINT FK_DC158CB88A652BD6 FOREIGN KEY (edificio_id) REFERENCES edificio (id)');
        $this->addSql('CREATE INDEX IDX_DC158CB88A652BD6 ON ubicacion (edificio_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE programacion DROP FOREIGN KEY FK_14491F898A652BD6');
        $this->addSql('ALTER TABLE ubicacion DROP FOREIGN KEY FK_DC158CB88A652BD6');
        $this->addSql('ALTER TABLE pantalla DROP FOREIGN KEY FK_EBB5F6CAE7089003');
        $this->addSql('ALTER TABLE telemetria DROP FOREIGN KEY FK_8C0CB1A5E7089003');
        $this->addSql('CREATE TABLE nodo (id INT UNSIGNED AUTO_INCREMENT NOT NULL, pantalla_id INT UNSIGNED NOT NULL, hostname VARCHAR(100) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, version_firmware VARCHAR(50) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, api_key_hash VARCHAR(64) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, ultimo_heartbeat DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', estado_conexion VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, UNIQUE INDEX UNIQ_65AA015B394D775 (pantalla_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE nodo ADD CONSTRAINT FK_65AA015B394D775 FOREIGN KEY (pantalla_id) REFERENCES pantalla (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE cronograma_item DROP FOREIGN KEY FK_F8A78EAB7FDA517C');
        $this->addSql('DROP TABLE cronograma_item');
        $this->addSql('DROP TABLE edificio');
        $this->addSql('DROP TABLE reproductor');
        $this->addSql('ALTER TABLE contenido CHANGE duracion_segundos duracion_segundos SMALLINT UNSIGNED NOT NULL');
        $this->addSql('DROP INDEX IDX_DC158CB88A652BD6 ON ubicacion');
        $this->addSql('ALTER TABLE ubicacion ADD edificio VARCHAR(100) NOT NULL, ADD aula VARCHAR(100) DEFAULT NULL, DROP edificio_id, DROP sector');
        $this->addSql('ALTER TABLE pantalla DROP FOREIGN KEY FK_EBB5F6CA8129233C');
        $this->addSql('DROP INDEX IDX_EBB5F6CAE7089003 ON pantalla');
        $this->addSql('DROP INDEX IDX_EBB5F6CA8129233C ON pantalla');
        $this->addSql('ALTER TABLE pantalla DROP reproductor_id, DROP playlist_fallback_id, CHANGE mac_address mac_address VARCHAR(17) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EBB5F6CAB728E969 ON pantalla (mac_address)');
        $this->addSql('DROP INDEX IDX_14491F898A652BD6 ON programacion');
        $this->addSql('ALTER TABLE programacion DROP edificio_id, DROP repetir_semanal');
        $this->addSql('DROP INDEX IDX_8C0CB1A5E7089003 ON telemetria');
        $this->addSql('DROP INDEX idx_reproductor_registrado ON telemetria');
        $this->addSql('ALTER TABLE telemetria CHANGE reproductor_id nodo_id INT UNSIGNED NOT NULL');
        $this->addSql('ALTER TABLE telemetria ADD CONSTRAINT FK_8C0CB1A529B07FB3 FOREIGN KEY (nodo_id) REFERENCES nodo (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_8C0CB1A529B07FB3 ON telemetria (nodo_id)');
        $this->addSql('CREATE INDEX idx_nodo_registrado ON telemetria (nodo_id, registrado_en)');
    }
}
