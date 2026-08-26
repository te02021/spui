<?php

declare(strict_types=1);

namespace SPUI\Service;

use DateTimeImmutable;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use SPUI\Entity\Reproductor;
use SPUI\Repository\ReproductorRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Valida la API key enviada por los reproductores Raspberry Pi en el header X-Api-Key.
 * La key raw se hashea con SHA-256 y se compara contra el hash almacenado.
 *
 * Tarea 1.4 (diagnóstico de auth): hasta acá, un intento fallido no dejaba
 * ningún rastro visible en el CMS — el único síntoma era el 401 que recibía
 * la Pi. El incidente real del 11/08 (una clave regenerada sin actualizar el
 * .env de la Pi) costó una tarde entera de diagnóstico a ciegas porque el
 * panel no distinguía "la Pi habla y la rechazo" de "la Pi no habla". Esto
 * agrega dos cosas, sin ninguna tabla ni cron nuevo:
 *   - Un contador efímero en caché (spui_umbral_conexion-style, se auto-purga
 *     por TTL) para el aviso general del dashboard.
 *   - Comparación contra la clave ANTERIOR de cada reproductor (vigente 48h
 *     tras regenerar) para señalar el caso puntual que costó la tarde.
 */
final class ReproductorAuthService
{
    private const CACHE_KEY = 'spui_auth_intentos_fallidos';
    private const VENTANA_SEG = 3600;
    private const MAX_GUARDADOS = 50;

    public function __construct(
        private readonly ReproductorRepository $repo,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {}

    public function autenticar(Request $request): ?Reproductor
    {
        $rawKey = trim((string) $request->headers->get('X-Api-Key', ''));
        if ($rawKey === '') {
            return null;
        }

        $reproductor = $this->repo->findByApiKeyHash($rawKey);
        if ($reproductor !== null) {
            return $reproductor;
        }

        $this->registrarIntentoFallido($request, $rawKey);

        return null;
    }

    public function autenticarOFallar(Request $request): Reproductor
    {
        $reproductor = $this->autenticar($request);
        if ($reproductor === null) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException(
                'X-Api-Key',
                'API key inválida o ausente.',
            );
        }

        return $reproductor;
    }

    /**
     * Intentos fallidos en la última hora, para el aviso del dashboard.
     *
     * @return list<array{en: string, ip: string, ruta: string, reproductor_clave_vieja: ?string}>
     */
    public function intentosFallidosUltimaHora(): array
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        if (!$item->isHit()) {
            return [];
        }

        return $this->vigentes($item->get());
    }

    private function registrarIntentoFallido(Request $request, string $rawKey): void
    {
        // Si matchea la clave anterior de algún reproductor, el diagnóstico
        // es preciso: no es un desconocido, es un equipo real con la clave
        // vieja en su .env. Si no matchea nada, sigue siendo útil saber que
        // algo golpeó la puerta y desde dónde.
        $reproductorConClaveVieja = $this->repo->findByApiKeyHashAnterior($rawKey);

        $intento = [
            'en'                      => (new DateTimeImmutable())->format('c'),
            'ip'                      => $request->getClientIp() ?? '?',
            'ruta'                    => $request->getPathInfo(),
            'reproductor_clave_vieja' => $reproductorConClaveVieja?->getHostname(),
        ];

        $item     = $this->cache->getItem(self::CACHE_KEY);
        $intentos = $this->vigentes($item->isHit() ? $item->get() : []);
        $intentos[] = $intento;
        if (count($intentos) > self::MAX_GUARDADOS) {
            $intentos = array_slice($intentos, -self::MAX_GUARDADOS);
        }

        $item->set($intentos);
        $item->expiresAfter(self::VENTANA_SEG);
        $this->cache->save($item);

        if ($reproductorConClaveVieja !== null) {
            $this->logger->warning(
                'Intento de auth con clave vieja: "{hostname}" (id={id}) todavía usa la clave anterior a su última regeneración.',
                ['hostname' => $reproductorConClaveVieja->getHostname(), 'id' => $reproductorConClaveVieja->getId()],
            );
        } else {
            $this->logger->info('Intento de auth con API key inválida.', ['ip' => $intento['ip'], 'ruta' => $intento['ruta']]);
        }
    }

    /** @param list<array{en: string}> $intentos */
    private function vigentes(array $intentos): array
    {
        $corte = time() - self::VENTANA_SEG;
        return array_values(array_filter(
            $intentos,
            fn(array $i) => (strtotime($i['en']) ?: 0) > $corte,
        ));
    }
}
