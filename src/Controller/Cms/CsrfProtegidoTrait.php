<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rechaza acciones de un solo botón (activar/desactivar/eliminar/toggle/etc.)
 * sin token CSRF válido.
 *
 * Estas acciones son POST sin Symfony Form de por medio (createForm() ya trae
 * su propio CSRF vía el campo _token del form) — dependían sólo de
 * cookie_samesite: lax en la sesión, que bloquea el auto-submit cross-site
 * clásico pero que OWASP no reconoce como sustituto de un token propio.
 *
 * El JS (modal.js) manda el token en el campo '_token' de cada POST que
 * dispara desde data-spui-action / data-spui-action2 / data-spui-action2-now /
 * data-spui-post2, leído una sola vez de <meta name="spui-csrf-token"> en
 * base.html.twig. Un POST con body JSON (Content-Type: application/json) no
 * tiene dónde poner un campo de formulario, así que ahí el token viaja en el
 * header X-Csrf-Token en su lugar — mismo meta tag, mismo valor.
 *
 * Un solo intent ('spui_action') para todas: no hace falta uno distinto por
 * endpoint — el token igual queda atado a la sesión del usuario autenticado y
 * un atacante externo no tiene forma de leerlo ni de forjarlo.
 *
 * Está como trait porque el chequeo se repite en unas veinticinco acciones de
 * once controladores, siempre con la misma forma — mismo criterio que ya usa
 * BloqueoOfflineTrait para el bloqueo por reproductor desconectado.
 */
trait CsrfProtegidoTrait
{
    private function denegarSiCsrfInvalido(Request $request): ?Response
    {
        $token = $request->request->get('_token') ?? $request->headers->get('X-Csrf-Token');

        if ($this->isCsrfTokenValid('spui_action', $token)) {
            return null;
        }

        $mensaje = 'Token de seguridad inválido o vencido. Recargá la página e intentá de nuevo.';

        if ($request->isXmlHttpRequest()) {
            return $this->json(
                ['success' => false, 'message' => $mensaje, 'type' => 'error'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $this->addFlash('error', $mensaje);

        return $this->redirect($request->headers->get('referer') ?: '/spui');
    }
}
