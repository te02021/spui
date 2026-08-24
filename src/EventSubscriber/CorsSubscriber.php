<?php

declare(strict_types=1);

namespace SPUI\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Añade headers CORS a todas las respuestas bajo /api/spui/*.
 * Permite que el frontend CMS (Twig/JS) y herramientas externas (Postman, app móvil)
 * puedan consumir la API desde cualquier origen en dev.
 * En prod restringir $allowedOrigins a dominios específicos.
 */
final class CorsSubscriber implements EventSubscriberInterface
{
    private const API_PREFIX = '/api/spui';

    private const ALLOWED_HEADERS = 'Content-Type, X-Api-Key, Authorization, Accept';
    private const ALLOWED_METHODS = 'GET, POST, PATCH, DELETE, OPTIONS';

    public function __construct(
        /**
         * Orígenes permitidos, separados por coma (env SPUI_CORS_ORIGINS).
         * '*' habilita cualquier origen — sólo aceptable en desarrollo.
         *
         * Los reproductores Pi no se ven afectados por CORS: usan requests
         * server-to-server (Python/requests), no un navegador.
         */
        #[Autowire('%env(default:spui_cors_default:SPUI_CORS_ORIGINS)%')]
        private readonly string $allowedOrigins = '',
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST  => ['onKernelRequest', 9999],   // antes del routing
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }

    /** Intercepta preflight OPTIONS y responde 204 directamente */
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), self::API_PREFIX)) {
            return;
        }

        if ($request->getMethod() !== 'OPTIONS') {
            return;
        }

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $this->addCorsHeaders($response, $request->headers->get('Origin'));
        $event->setResponse($response);
    }

    /** Añade headers CORS a todas las respuestas de la API */
    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), self::API_PREFIX)) {
            return;
        }

        $this->addCorsHeaders($event->getResponse(), $request->headers->get('Origin'));
    }

    /**
     * Sólo emite Access-Control-Allow-Origin si el origen está permitido.
     * Si no lo está, no se emite el header y el navegador bloquea la respuesta.
     */
    private function addCorsHeaders(Response $response, ?string $origin): void
    {
        $permitido = $this->resolverOrigen($origin);
        if ($permitido === null) {
            // Vary igual, para que un proxy no cachee una respuesta permisiva
            // y se la sirva a un origen que no debería recibirla.
            $response->headers->set('Vary', 'Origin');
            return;
        }

        $response->headers->set('Access-Control-Allow-Origin', $permitido);
        $response->headers->set('Access-Control-Allow-Headers', self::ALLOWED_HEADERS);
        $response->headers->set('Access-Control-Allow-Methods', self::ALLOWED_METHODS);
        $response->headers->set('Access-Control-Max-Age', '3600');
        $response->headers->set('Vary', 'Origin');
    }

    /** Devuelve el valor a emitir en Allow-Origin, o null si el origen no está permitido. */
    private function resolverOrigen(?string $origin): ?string
    {
        $lista = array_filter(array_map('trim', explode(',', $this->allowedOrigins)));

        if (in_array('*', $lista, true)) {
            return $origin ?? '*';
        }
        if ($origin === null) {
            return null;
        }

        return in_array($origin, $lista, true) ? $origin : null;
    }
}
