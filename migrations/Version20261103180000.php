<?php

declare(strict_types=1);

namespace Shared\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un QR de app con los dos enlaces de tienda (03/11/2026): un solo código y
 * cada teléfono va a la suya (CodigoQr::destinoPara()). Los QR que ya existen
 * quedan con un solo destino, como antes.
 */
final class Version20261103180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'codigo_qr.url_destino_alternativa: segundo enlace de un QR de app.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE codigo_qr ADD url_destino_alternativa VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE codigo_qr DROP url_destino_alternativa');
    }
}
