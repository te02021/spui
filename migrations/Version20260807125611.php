<?php

declare(strict_types=1);

namespace Shared\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CU-10 — Vincula un Contenido de tipo `qr` con su CodigoQr.
 *
 * Sin este vínculo el QR que se mostraba en pantalla codificaba la URL destino
 * directamente, así que los escaneos nunca pasaban por el redirect del CMS y
 * usos_count quedaba siempre en 0.
 *
 * La columna es nullable (sólo aplica al tipo `qr`) y la FK usa ON DELETE SET
 * NULL: borrar un código QR no debe borrar el contenido que lo usaba.
 */
final class Version20260807125611 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega contenido.codigo_qr_id para vincular contenidos QR con su código (CU-10).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contenido ADD codigo_qr_id INT UNSIGNED DEFAULT NULL');
        $this->addSql('ALTER TABLE contenido ADD CONSTRAINT FK_D0A7397F9751B5A FOREIGN KEY (codigo_qr_id) REFERENCES codigo_qr (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_D0A7397F9751B5A ON contenido (codigo_qr_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contenido DROP FOREIGN KEY FK_D0A7397F9751B5A');
        $this->addSql('DROP INDEX IDX_D0A7397F9751B5A ON contenido');
        $this->addSql('ALTER TABLE contenido DROP codigo_qr_id');
    }
}
