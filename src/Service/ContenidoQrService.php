<?php

declare(strict_types=1);

namespace SPUI\Service;

use SPUI\Entity\CodigoQr;
use SPUI\Entity\Contenido;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Materializa el QR de un Contenido como archivo PNG (CU-10).
 *
 * Por qué archivo y no generación al vuelo: el Pi ya sabe descargar, verificar
 * por SHA-256 y cachear archivos de medios para el modo offline. Guardando el
 * PNG igual que una imagen, un contenido QR viaja por esa misma tubería sin
 * ningún caso especial en el cliente — y se sigue viendo con la red caída.
 *
 * El PNG codifica la URL de redirect del CMS (/api/spui/qr/{id}/r), no la URL
 * destino. Así cada escaneo pasa por el CMS, incrementa usos_count, y se puede
 * cambiar el destino sin reimprimir nada.
 */
final class ContenidoQrService
{
    public function __construct(
        private readonly QrGeneratorService $qrGenerator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly MediaStorageService $media,
    ) {}

    /**
     * Genera el PNG del código y lo deja asociado al contenido.
     * Si el contenido ya tenía un PNG generado, lo reemplaza.
     */
    public function materializar(Contenido $contenido, CodigoQr $codigo): void
    {
        $anterior = $contenido->getRutaArchivo();

        $redirectUrl = $this->urlGenerator->generate(
            'spui_qr_redirect',
            ['id' => $codigo->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $filename = sprintf('qr-%d-%s.png', $codigo->getId(), uniqid());
        $png      = $this->qrGenerator->generarPng($redirectUrl);

        // El almacenamiento (disco o S3) lo resuelve MediaStorageService.
        $rutaArchivo = $this->media->guardarContenido($png, $filename, 'qr');

        $contenido->setCodigoQr($codigo);
        $contenido->setRutaArchivo($rutaArchivo);
        // Se calcula sobre el mismo binario que se guardó, sin releerlo.
        $contenido->setHashArchivo(hash('sha256', $png));
        // El texto guarda el destino sólo como referencia legible en el CMS;
        // lo que el QR codifica es el redirect, no esto.
        $contenido->setContenidoTexto($codigo->getUrlDestino());

        $this->borrarArchivo($anterior);
    }

    /** Borra el PNG asociado a un contenido (al eliminarlo o cambiarle el código). */
    public function borrarArchivo(?string $rutaRelativa): void
    {
        $this->media->borrar($rutaRelativa);
    }
}
