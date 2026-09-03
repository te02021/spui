<?php

declare(strict_types=1);

namespace Shared\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Guarda qué URL quedó codificada dentro del PNG de cada código QR.
 *
 * Sin este dato no hay forma barata de saber que el CMS cambió de dirección y
 * que los PNG guardados apuntan a un host que ya no existe: habría que bajar y
 * decodificar cada imagen. Con la columna, el panel lo detecta solo y los
 * rehace — que es lo que reemplaza al comando manual que había antes.
 *
 * Las filas existentes quedan en NULL ("no se sabe") y se reconcilian solas la
 * primera vez que alguien entra a Contenidos.
 */
final class Version20260903130835 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'codigo_qr.url_generada: la URL que lleva adentro el PNG ya generado.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE codigo_qr ADD url_generada VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE codigo_qr DROP url_generada');
    }
}
