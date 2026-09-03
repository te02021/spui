<?php

declare(strict_types=1);

namespace SPUI\Controller\Api;

use SPUI\Repository\CodigoQrRepository;
use SPUI\Service\QrEscaneoPublisherService;
use SPUI\Service\QrGeneratorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sólo la imagen del QR y su redirect — son las dos rutas públicas que de
 * verdad escanea un celular. El CRUD (index/create/show/update/delete) que
 * tenía este controller antes era un duplicado JSON de CodigoQrCmsController,
 * sin ningún consumidor real (nada en el CMS ni en el pi-client lo llamaba):
 * se dio de baja junto con el resto de la capa Api/*Controller huérfana
 * (Alerta/Contenido/Pantalla/Playlist/Programacion/Reproductor/Ubicacion).
 */
#[Route('/api/spui/qr')]
class CodigoQrController extends AbstractController
{
    public function __construct(
        private readonly CodigoQrRepository $repo,
        private readonly QrGeneratorService $qrGenerator,
        private readonly QrEscaneoPublisherService $escaneoPublisher,
    ) {}

    /**
     * Devuelve la imagen PNG del QR.
     * El QR codifica la URL de redirect del CMS, no la URL destino directamente
     * (así el contador funciona aunque cambie la URL destino).
     */
    // \d{1,10} y no \d+: la columna es un int unsigned de MySQL (máximo
    // 4294967295, 10 dígitos). Sin el límite superior, un id de 25+ dígitos
    // igual matchea la ruta (son sólo dígitos) pero desborda el rango de
    // int de PHP al castear — Symfony pasa un string donde el parámetro
    // pide int y explota con un TypeError no capturado, 500 en el único
    // endpoint público de todo el CMS que cualquiera en internet puede
    // tocar sin sesión. Confirmado: curl .../qr/999999999999999999999999/r
    // devolvía 500 en vez de 404.
    #[Route('/{id}/imagen', name: 'spui_qr_imagen', methods: ['GET'], requirements: ['id' => '\d{1,10}'])]
    public function imagen(int $id): Response
    {
        $qr = $this->repo->find($id);
        if ($qr === null) {
            return $this->json(['error' => 'Código QR no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        // Qué URL codifica (el endpoint /r, no la URL destino) lo resuelve
        // QrGeneratorService, el mismo que usa ContenidoQrService al guardar el
        // PNG que va a la pantalla — así la vista previa del CMS y lo que se ve
        // en el reproductor no pueden apuntar a hosts distintos.
        $pngBytes = $this->qrGenerator->generarPng($this->qrGenerator->urlRedirect($id));

        return new Response(
            $pngBytes,
            Response::HTTP_OK,
            [
                'Content-Type'        => $this->qrGenerator->mimeType(),
                'Content-Disposition' => 'inline; filename="qr-' . $id . '.png"',
                'Cache-Control'       => 'public, max-age=3600',
            ],
        );
    }

    /**
     * Endpoint de redirect: scaneado el QR, el dispositivo llega aquí.
     * Incrementa el contador y redirige a url_destino.
     * Es público (sin auth): cualquier celular puede escanear el QR.
     *
     * Cuando el código ya no sirve se responde 410 con una página propia en vez
     * de un redirect: quien escanea tiene que entender qué pasó, y mandarlo a
     * cualquier otro lado sin avisar sería peor. La plantilla es autocontenida
     * (ver el comentario de arriba de todo en no_disponible.html.twig) porque
     * del otro lado hay un celular ajeno, sin sesión en la intranet.
     */
    #[Route('/{id}/r', name: 'spui_qr_redirect', methods: ['GET'], requirements: ['id' => '\d{1,10}'])]
    public function redirigir(int $id): Response
    {
        $qr = $this->repo->find($id);

        if ($qr === null || !$qr->estaVigente()) {
            // Vencido y dado de baja no son lo mismo para quien escanea: uno
            // tuvo fecha de fin conocida y el otro directamente ya no existe.
            $vencido = $qr !== null && $qr->isActivo();

            $respuesta = new Response('', Response::HTTP_GONE);
            // Sin cachear: si el código vuelve a estar vigente, un 410 guardado
            // en el teléfono lo dejaría roto para esa persona para siempre.
            $respuesta->headers->set('Cache-Control', 'no-store, max-age=0');

            return $this->render('@SPUI/qr/no_disponible.html.twig', [
                'vencido'   => $vencido,
                'expira_en' => $vencido ? $qr->getExpiraEn() : null,
            ], $respuesta);
        }

        // UPDATE atómico en SQL, no un ++ en memoria + flush(): con varias
        // personas escaneando el mismo cartel casi al mismo tiempo, dos
        // requests que leyeran el mismo valor y lo guardaran por separado
        // perderían uno de los dos escaneos sin ningún error visible —
        // confirmado con 40 escaneos concurrentes reales, sólo 30 quedaron
        // contados. El UPDATE de MySQL no tiene esa ventana.
        $usos = $this->repo->incrementarUsosAtomico($qr->getId());
        $qr->setUsosCount($usos);

        // Después del UPDATE: el panel tiene que enterarse del número que
        // quedó guardado, no de uno que todavía podría no persistirse. El
        // publish nunca puede demorar el redirect de quien escanea — el
        // servicio tiene timeout corto y falla en silencio si el broker no
        // está.
        $this->escaneoPublisher->publicar($qr);

        return new RedirectResponse($qr->getUrlDestino(), Response::HTTP_FOUND);
    }
}
