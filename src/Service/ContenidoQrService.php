<?php

declare(strict_types=1);

namespace SPUI\Service;

use Psr\Log\LoggerInterface;
use SPUI\Entity\CodigoQr;
use SPUI\Entity\Contenido;
use SPUI\Enum\TipoContenido;
use SPUI\Repository\CodigoQrRepository;
use SPUI\Repository\ContenidoRepository;

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
        private readonly MediaStorageService $media,
        private readonly ContenidoRepository $repo,
        private readonly CodigoQrRepository $codigoQrRepo,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Genera el PNG del código y lo deja asociado al contenido.
     * Si el contenido ya tenía un PNG generado, lo reemplaza.
     *
     * No toca CodigoQr::urlGenerada: quien decide y reserva esa URL es el
     * caller (aplicarDatosQr() al crear/editar un contenido nuevo, o
     * sincronizarUrls() al reconciliar), porque sincronizarUrls() necesita
     * reservarla ANTES de generar el PNG para evitar la carrera — ver el
     * comentario de CodigoQrRepository::reservarParaRegenerar().
     */
    public function materializar(Contenido $contenido, CodigoQr $codigo): void
    {
        $anterior = $contenido->getRutaArchivo();

        // Qué URL codifica el PNG lo decide QrGeneratorService, para que sea
        // exactamente la misma que sirve /api/spui/qr/{id}/imagen: si cada uno
        // la armara por su cuenta, el QR de la vista previa del CMS y el que
        // sale en la pantalla podrían apuntar a hosts distintos.
        $url      = $this->qrGenerator->urlRedirect($codigo->getId());
        $filename = sprintf('qr-%d-%s.png', $codigo->getId(), uniqid());
        $png      = $this->qrGenerator->generarPng($url);

        // El almacenamiento (disco o S3) lo resuelve MediaStorageService.
        $rutaArchivo = $this->media->guardarContenido($png, $filename, 'qr');

        $contenido->setCodigoQr($codigo);
        $contenido->setRutaArchivo($rutaArchivo);
        // Se calcula sobre el mismo binario que se guardó, sin releerlo.
        $contenido->setHashArchivo(hash('sha256', $png));
        // El texto guarda el destino sólo como referencia legible en el CMS;
        // lo que el QR codifica es el redirect, no esto.
        $contenido->setContenidoTexto($codigo->getUrlDestino());
        // Deja la entidad en memoria en sync con lo que ya quedó reservado en
        // la base (ver el docblock de arriba); no es lo que persiste el valor.
        $codigo->setUrlGenerada($url);

        $this->borrarArchivo($anterior);
    }

    /**
     * Rehace los PNG que quedaron apuntando a una dirección vieja.
     *
     * Reemplaza al comando de consola que había antes. El motivo de fondo es
     * que la URL correcta sólo se conoce dentro de una request: es la
     * dirección por la que el operador acaba de entrar al CMS. En consola no
     * existe ese dato, y taparlo con una variable de entorno significaba que
     * si alguien la olvidaba en producción los QR salían impresos y rotos, sin
     * que nada fallara a la vista. Corriendo desde el panel el dato está
     * siempre, en desarrollo y en producción, sin configurar nada.
     *
     * NO regenera todo lo que no coincide: aplica la regla de rango de
     * QrGeneratorService::rangoDeBase(). Regenerar de más se ve en una
     * pantalla real — la Pi resuelve el archivo por su nombre y al cambiar
     * tiene que volver a descargarlo. Ver el comentario de esa función.
     *
     * Falla en silencio por contenido: esto corre al abrir el listado, y que
     * S3 esté caído no puede dejar al operador sin poder ver sus contenidos.
     *
     * Sin ninguna forma de saltear la regla de rango: no hace falta. El caso
     * frecuente es desarrollo cambiando de red (misma jerarquía, se
     * regenera); degradar a propósito una URL de producción es tan raro que
     * no amerita un control en el panel — si hiciera falta, se edita
     * urlGenerada a mano en la base.
     *
     * @return array{regenerados: list<Contenido>, fallos: int} `fallos` importa:
     *         el que llama no debe dar la pasada por completa si hubo alguno,
     *         o se saltearía la próxima creyendo que ya está todo al día.
     */
    public function sincronizarUrls(): array
    {
        $regenerados = [];
        $fallos      = 0;

        foreach ($this->repo->findBy(['tipo' => TipoContenido::Qr]) as $contenido) {
            $codigo = $contenido->getCodigoQr();
            if ($codigo === null || $codigo->getId() === null) {
                continue;
            }

            $urlNueva = $this->qrGenerator->urlRedirect($codigo->getId());
            $urlVieja = $codigo->getUrlGenerada();

            // Al día, que es el caso normal y el de producción siempre.
            if ($urlVieja === $urlNueva && $contenido->getRutaArchivo() !== null) {
                continue;
            }

            // La regla anti-churn. urlVieja null = código anterior a la
            // columna: se regenera sin más, no hay con qué comparar.
            if ($urlVieja !== null
                && QrGeneratorService::rangoDeBase($urlNueva) > QrGeneratorService::rangoDeBase($urlVieja)) {
                $this->logger->info('QR {id}: se ignora {nueva}, es menos pública que {vieja}.', [
                    'id'     => $codigo->getId(),
                    'nueva'  => $urlNueva,
                    'vieja'  => $urlVieja,
                ]);
                continue;
            }

            // Reserva ANTES de generar nada: si otro proceso concurrente ya
            // ganó esta misma regeneración (mismo id, mismo urlVieja leído),
            // este UPDATE afecta 0 filas y hay que retirarse acá — sin haber
            // gastado el trabajo de generar el PNG ni de subirlo a S3. Sin
            // esto, confirmado con 8 procesos reales en paralelo: los 8
            // llegaban a materializar(), subían 8 PNG distintos, y sólo 1
            // quedaba referenciado — los otros 7 huérfanos en el bucket para
            // siempre. Ver el docblock de reservarParaRegenerar().
            if (!$this->codigoQrRepo->reservarParaRegenerar($codigo->getId(), $urlVieja, $urlNueva)) {
                $this->logger->info('QR {id}: otro proceso ya lo estaba regenerando, se omite.', [
                    'id' => $codigo->getId(),
                ]);
                continue;
            }

            try {
                $this->materializar($contenido, $codigo);
                $regenerados[] = $contenido;

                $this->logger->info('QR {id} regenerado: {vieja} -> {nueva}.', [
                    'id'    => $codigo->getId(),
                    'vieja' => $urlVieja ?? '(desconocida)',
                    'nueva' => $urlNueva,
                ]);
            } catch (\Throwable $e) {
                // Si materializar() falla (S3 caído a mitad de operación), la
                // reserva de arriba ya dejó url_generada = urlNueva en la
                // base aunque el PNG real nunca se subió. Sin deshacerla, la
                // próxima visita compararía "urlVieja === urlNueva" (ambas ya
                // son urlNueva) y daría el código por al día para siempre,
                // dejando un contenido con el PNG viejo colgado. Se revierte
                // a $urlVieja para que el próximo sincronizarUrls() lo vuelva
                // a intentar — el listado igual renderiza normal, esto no se
                // propaga como error al operador.
                $this->codigoQrRepo->reservarParaRegenerar($codigo->getId(), $urlNueva, $urlVieja);

                $fallos++;
                $this->logger->warning('QR {id}: no se pudo regenerar el PNG: {msg}', [
                    'id'  => $codigo->getId(),
                    'msg' => $e->getMessage(),
                ]);
            }
        }

        return ['regenerados' => $regenerados, 'fallos' => $fallos];
    }

    /** Borra el PNG asociado a un contenido (al eliminarlo o cambiarle el código). */
    public function borrarArchivo(?string $rutaRelativa): void
    {
        $this->media->borrar($rutaRelativa);
    }
}
