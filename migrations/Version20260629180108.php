<?php

declare(strict_types=1);

namespace SPUI\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260629180108 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE alerta_emergencia (id INT UNSIGNED AUTO_INCREMENT NOT NULL, contenido_id INT UNSIGNED DEFAULT NULL, titulo VARCHAR(200) NOT NULL, mensaje LONGTEXT NOT NULL, activa TINYINT(1) NOT NULL, prioridad SMALLINT UNSIGNED NOT NULL, creado_por_id INT UNSIGNED NOT NULL, creada_en DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', activada_en DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', expira_en DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_D954D5047FDA517C (contenido_id), INDEX idx_alerta_activa (activa), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE codigo_qr (id INT UNSIGNED AUTO_INCREMENT NOT NULL, url_destino VARCHAR(500) NOT NULL, etiqueta VARCHAR(200) NOT NULL, expira_en DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', usos_count INT UNSIGNED NOT NULL, activo TINYINT(1) NOT NULL, creado_en DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE contenido (id INT UNSIGNED AUTO_INCREMENT NOT NULL, titulo VARCHAR(200) NOT NULL, tipo VARCHAR(255) NOT NULL, ruta_archivo VARCHAR(500) DEFAULT NULL, contenido_texto LONGTEXT DEFAULT NULL, duracion_segundos SMALLINT UNSIGNED NOT NULL, hash_archivo VARCHAR(64) DEFAULT NULL, estado VARCHAR(255) NOT NULL, creado_por_id INT UNSIGNED NOT NULL, creado_en DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', actualizado_en DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE nodo (id INT UNSIGNED AUTO_INCREMENT NOT NULL, pantalla_id INT UNSIGNED NOT NULL, hostname VARCHAR(100) NOT NULL, version_firmware VARCHAR(50) DEFAULT NULL, api_key_hash VARCHAR(64) NOT NULL, ultimo_heartbeat DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', estado_conexion VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_65AA015B394D775 (pantalla_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE pantalla (id INT UNSIGNED AUTO_INCREMENT NOT NULL, ubicacion_id INT UNSIGNED NOT NULL, nombre VARCHAR(150) NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, mac_address VARCHAR(17) NOT NULL, resolucion_ancho SMALLINT UNSIGNED NOT NULL, resolucion_alto SMALLINT UNSIGNED NOT NULL, estado VARCHAR(255) NOT NULL, creado_en DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', actualizado_en DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_EBB5F6CAB728E969 (mac_address), INDEX IDX_EBB5F6CA57E759E8 (ubicacion_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE playlist (id INT UNSIGNED AUTO_INCREMENT NOT NULL, nombre VARCHAR(200) NOT NULL, descripcion LONGTEXT DEFAULT NULL, activo TINYINT(1) NOT NULL, creado_por_id INT UNSIGNED NOT NULL, creado_en DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', actualizado_en DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE playlist_item (id INT UNSIGNED AUTO_INCREMENT NOT NULL, playlist_id INT UNSIGNED NOT NULL, contenido_id INT UNSIGNED NOT NULL, orden SMALLINT UNSIGNED NOT NULL, duracion_override_seg SMALLINT UNSIGNED DEFAULT NULL, INDEX IDX_BF02127C6BBD148 (playlist_id), INDEX IDX_BF02127C7FDA517C (contenido_id), UNIQUE INDEX uq_playlist_orden (playlist_id, orden), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE programacion (id INT UNSIGNED AUTO_INCREMENT NOT NULL, pantalla_id INT UNSIGNED DEFAULT NULL, ubicacion_id INT UNSIGNED DEFAULT NULL, playlist_id INT UNSIGNED NOT NULL, fecha_inicio DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', fecha_fin DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\', hora_inicio TIME NOT NULL COMMENT \'(DC2Type:time_immutable)\', hora_fin TIME NOT NULL COMMENT \'(DC2Type:time_immutable)\', dias_semana SMALLINT UNSIGNED NOT NULL, prioridad SMALLINT UNSIGNED NOT NULL, activo TINYINT(1) NOT NULL, creado_por_id INT UNSIGNED NOT NULL, creado_en DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_14491F89394D775 (pantalla_id), INDEX IDX_14491F8957E759E8 (ubicacion_id), INDEX IDX_14491F896BBD148 (playlist_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE programacion_energetica (id INT UNSIGNED AUTO_INCREMENT NOT NULL, pantalla_id INT UNSIGNED NOT NULL, dia_semana SMALLINT UNSIGNED NOT NULL, hora_encendido TIME NOT NULL COMMENT \'(DC2Type:time_immutable)\', hora_apagado TIME NOT NULL COMMENT \'(DC2Type:time_immutable)\', nivel_brillo SMALLINT UNSIGNED NOT NULL, INDEX IDX_3865032394D775 (pantalla_id), UNIQUE INDEX uq_pantalla_dia (pantalla_id, dia_semana), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE telemetria (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, nodo_id INT UNSIGNED NOT NULL, temperatura_soc_celsius NUMERIC(5, 2) NOT NULL, uso_ram_porcentaje NUMERIC(5, 2) NOT NULL, latencia_red_ms SMALLINT UNSIGNED DEFAULT NULL, espacio_disco_libre_mb INT UNSIGNED NOT NULL, registrado_en DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_8C0CB1A529B07FB3 (nodo_id), INDEX idx_nodo_registrado (nodo_id, registrado_en), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE ubicacion (id INT UNSIGNED AUTO_INCREMENT NOT NULL, edificio VARCHAR(100) NOT NULL, aula VARCHAR(100) DEFAULT NULL, descripcion LONGTEXT DEFAULT NULL, activo TINYINT(1) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE alerta_emergencia ADD CONSTRAINT FK_D954D5047FDA517C FOREIGN KEY (contenido_id) REFERENCES contenido (id)');
        $this->addSql('ALTER TABLE nodo ADD CONSTRAINT FK_65AA015B394D775 FOREIGN KEY (pantalla_id) REFERENCES pantalla (id)');
        $this->addSql('ALTER TABLE pantalla ADD CONSTRAINT FK_EBB5F6CA57E759E8 FOREIGN KEY (ubicacion_id) REFERENCES ubicacion (id)');
        $this->addSql('ALTER TABLE playlist_item ADD CONSTRAINT FK_BF02127C6BBD148 FOREIGN KEY (playlist_id) REFERENCES playlist (id)');
        $this->addSql('ALTER TABLE playlist_item ADD CONSTRAINT FK_BF02127C7FDA517C FOREIGN KEY (contenido_id) REFERENCES contenido (id)');
        $this->addSql('ALTER TABLE programacion ADD CONSTRAINT FK_14491F89394D775 FOREIGN KEY (pantalla_id) REFERENCES pantalla (id)');
        $this->addSql('ALTER TABLE programacion ADD CONSTRAINT FK_14491F8957E759E8 FOREIGN KEY (ubicacion_id) REFERENCES ubicacion (id)');
        $this->addSql('ALTER TABLE programacion ADD CONSTRAINT FK_14491F896BBD148 FOREIGN KEY (playlist_id) REFERENCES playlist (id)');
        $this->addSql('ALTER TABLE programacion_energetica ADD CONSTRAINT FK_3865032394D775 FOREIGN KEY (pantalla_id) REFERENCES pantalla (id)');
        $this->addSql('ALTER TABLE telemetria ADD CONSTRAINT FK_8C0CB1A529B07FB3 FOREIGN KEY (nodo_id) REFERENCES nodo (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alerta_emergencia DROP FOREIGN KEY FK_D954D5047FDA517C');
        $this->addSql('ALTER TABLE nodo DROP FOREIGN KEY FK_65AA015B394D775');
        $this->addSql('ALTER TABLE pantalla DROP FOREIGN KEY FK_EBB5F6CA57E759E8');
        $this->addSql('ALTER TABLE playlist_item DROP FOREIGN KEY FK_BF02127C6BBD148');
        $this->addSql('ALTER TABLE playlist_item DROP FOREIGN KEY FK_BF02127C7FDA517C');
        $this->addSql('ALTER TABLE programacion DROP FOREIGN KEY FK_14491F89394D775');
        $this->addSql('ALTER TABLE programacion DROP FOREIGN KEY FK_14491F8957E759E8');
        $this->addSql('ALTER TABLE programacion DROP FOREIGN KEY FK_14491F896BBD148');
        $this->addSql('ALTER TABLE programacion_energetica DROP FOREIGN KEY FK_3865032394D775');
        $this->addSql('ALTER TABLE telemetria DROP FOREIGN KEY FK_8C0CB1A529B07FB3');
        $this->addSql('DROP TABLE alerta_emergencia');
        $this->addSql('DROP TABLE codigo_qr');
        $this->addSql('DROP TABLE contenido');
        $this->addSql('DROP TABLE nodo');
        $this->addSql('DROP TABLE pantalla');
        $this->addSql('DROP TABLE playlist');
        $this->addSql('DROP TABLE playlist_item');
        $this->addSql('DROP TABLE programacion');
        $this->addSql('DROP TABLE programacion_energetica');
        $this->addSql('DROP TABLE telemetria');
        $this->addSql('DROP TABLE ubicacion');
    }
}
