<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

use SPUI\Entity\Reproductor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rechaza acciones dirigidas a reproductores desconectados.
 *
 * El bloqueo tiene que estar en el servidor y no sólo en la interfaz: las rutas
 * son alcanzables directamente, y basta con que un equipo se caiga entre que se
 * renderizó la página y el usuario hizo clic para que la acción pase igual.
 *
 * Está como trait porque el chequeo se repite en unas veinte acciones de seis
 * controladores, siempre con la misma forma: resolver a quién afecta, y si hay
 * alguno caído devolver 409 en el formato que ya usa el resto del CMS (JSON
 * para los modales, flash + redirect para navegación normal).
 *
 * Requiere que el controlador tenga una propiedad $alcance con un
 * AlcanceReproductorService.
 */
trait BloqueoOfflineTrait
{
    /**
     * Devuelve la respuesta de bloqueo, o null si la acción puede continuar.
     *
     * Uso:
     *     if ($r = $this->bloquearSiOffline($this->alcance->deAlerta($a), $request, 'spui_cms_alertas_index')) {
     *         return $r;
     *     }
     *
     * @param Reproductor[] $reproductores A quiénes llega la acción.
     * @param string        $rutaRedirect  Adónde volver en navegación normal.
     */
    private function bloquearSiOffline(
        array $reproductores,
        Request $request,
        string $rutaRedirect,
    ): ?Response {
        $motivo = $this->alcance->bloqueoPara($reproductores);

        if ($motivo === null) {
            return null;
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json(
                ['success' => false, 'message' => $motivo, 'type' => 'warning'],
                Response::HTTP_CONFLICT,
            );
        }

        $this->addFlash('warning', $motivo);

        return $this->redirectToRoute($rutaRedirect);
    }
}
