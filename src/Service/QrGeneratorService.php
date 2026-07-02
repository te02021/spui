<?php

declare(strict_types=1);

namespace SPUI\Service;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Genera imágenes QR en PNG usando endroid/qr-code.
 * Recibe la URL destino (redirect del CMS) y devuelve los bytes PNG.
 */
final class QrGeneratorService
{
    private const SIZE   = 300;
    private const MARGIN = 10;

    public function generarPng(string $url, int $size = self::SIZE): string
    {
        $qrCode = new QrCode(
            data:   $url,
            size:   $size,
            margin: self::MARGIN,
        );

        return (new PngWriter())->write($qrCode)->getString();
    }

    public function mimeType(): string
    {
        return 'image/png';
    }
}
