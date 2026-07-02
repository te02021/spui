<?php

declare(strict_types=1);

namespace SPUI\EventSubscriber;

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
        $this->addCorsHeaders($response, $request->headers->get('Origin', '*'));
        $event->setResponse($response);
    }

    /** Añade headers CORS a todas las respuestas de la API */
    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), self::API_PREFIX)) {
            return;
        }

        $this->addCorsHeaders($event->getResponse(), $request->headers->get('Origin', '*'));
    }

    private function addCorsHeaders(Response $response, string $origin): void
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Headers', self::ALLOWED_HEADERS);
        $response->headers->set('Access-Control-Allow-Methods', self::ALLOWED_METHODS);
        $response->headers->set('Access-Control-Max-Age', '3600');
        $response->headers->set('Vary', 'Origin');
    }
}
