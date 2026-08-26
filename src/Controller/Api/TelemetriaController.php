<?php

declare(strict_types=1);

namespace SPUI\Controller\Api;

use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Telemetria;
use SPUI\Repository\TelemetriaRepository;
use SPUI\Service\ReproductorAuthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Recibe telemetría del sistema enviada por los reproductores Raspberry Pi.
 * Auth: header X-Api-Key (misma que sync/heartbeat).
 */
#[Route('/api/spui/reproductores')]
class TelemetriaController extends AbstractController
{
    /**
     * Rangos válidos, para no persistir basura de un Pi con un bug o de una
     * key filtrada usada para inyectar datos (nada de esto es raro de
     * verificar: son las magnitudes físicas reales de una Raspberry Pi).
     */
    private const TEMP_MIN_C   = -40.0;
    private const TEMP_MAX_C   = 150.0;
    private const RAM_MIN_PCT  = 0.0;
    private const RAM_MAX_PCT  = 100.0;
    private const DISCO_MAX_MB = 100_000_000;   // 100 TB, generoso a propósito
    private const LATENCIA_MAX_MS = 300_000;    // 5 minutos: cualquier cosa más ya es "sin red"

    public function __construct(
        private readonly ReproductorAuthService $authService,
        private readonly ManagerRegistry $doctrine,
        private readonly TelemetriaRepository $repo,
        #[Autowire('%env(float:default:spui_temp_alerta_default:SPUI_TEMP_ALERTA_CELSIUS)%')]
        private readonly float $tempAlertaCelsius,
        private readonly RateLimiterFactory $spuiReproductorTelemetriaLimiter,
        private readonly RateLimiterFactory $spuiApiLimiter,
    ) {}

    #[Route('/telemetria', name: 'spui_reproductores_telemetria_ingestar', methods: ['POST'])]
    public function ingestar(Request $request): JsonResponse
    {
        $reproductor = $this->authService->autenticar($request);
        if ($reproductor === null) {
            return $this->json(
                ['error' => 'Autenticación requerida. Enviá X-Api-Key en el header.'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $limiter = $this->spuiReproductorTelemetriaLimiter->create('reproductor_telemetria_' . $reproductor->getId());
        $limit   = $limiter->consume();
        if (!$limit->isAccepted()) {
            return $this->json(
                ['error' => 'Demasiados envíos de telemetría.'],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => $limit->getRetryAfter()->getTimestamp() - time()],
            );
        }

        $body = json_decode($request->getContent(), true) ?? [];

        if (!isset($body['uso_ram_porcentaje'], $body['espacio_disco_libre_mb'])) {
            return $this->json(
                ['error' => 'Campos requeridos: uso_ram_porcentaje, espacio_disco_libre_mb.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $temp    = isset($body['temperatura_soc_celsius']) ? (float) $body['temperatura_soc_celsius'] : 0.0;
        $ram     = (float) $body['uso_ram_porcentaje'];
        $disco   = (int) $body['espacio_disco_libre_mb'];
        $latencia = isset($body['latencia_red_ms']) ? (int) $body['latencia_red_ms'] : null;

        $error = match (true) {
            $temp < self::TEMP_MIN_C || $temp > self::TEMP_MAX_C
                => sprintf('temperatura_soc_celsius fuera de rango (%.1f a %.1f).', self::TEMP_MIN_C, self::TEMP_MAX_C),
            $ram < self::RAM_MIN_PCT || $ram > self::RAM_MAX_PCT
                => 'uso_ram_porcentaje debe estar entre 0 y 100.',
            $disco < 0 || $disco > self::DISCO_MAX_MB
                => 'espacio_disco_libre_mb fuera de rango.',
            $latencia !== null && ($latencia < 0 || $latencia > self::LATENCIA_MAX_MS)
                => 'latencia_red_ms fuera de rango.',
            default => null,
        };
        if ($error !== null) {
            return $this->json(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $telemetria = new Telemetria();
        $telemetria->setReproductor($reproductor);
        $telemetria->setTemperaturaSocCelsius($temp);
        $telemetria->setUsoRamPorcentaje($ram);
        $telemetria->setLatenciaRedMs($latencia);
        $telemetria->setEspacioDiscoLibreMb($disco);

        $em = $this->doctrine->getManager('SPUI');
        $em->persist($telemetria);
        $em->flush();

        return $this->json([
            'ok'                 => true,
            'id'                 => $telemetria->getId(),
            'registrado_en'      => $telemetria->getRegistradoEn()->format('c'),
            'alerta_temperatura' => $temp >= $this->tempAlertaCelsius,
        ], Response::HTTP_CREATED);
    }

    #[Route('/telemetria', name: 'spui_reproductores_telemetria_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $reproductor = $this->authService->autenticar($request);
        if ($reproductor === null) {
            return $this->json(
                ['error' => 'Autenticación requerida. Enviá X-Api-Key en el header.'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        // spui_api (120/min por IP) no estaba cableada a ningún controller —
        // este GET es el primer uso real.
        $limiter    = $this->spuiApiLimiter->create($request->getClientIp() ?? 'sin-ip');
        $rateLimit  = $limiter->consume();
        if (!$rateLimit->isAccepted()) {
            return $this->json(
                ['error' => 'Demasiadas solicitudes.'],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => $rateLimit->getRetryAfter()->getTimestamp() - time()],
            );
        }

        // Antes sin piso: ?limit=-5 pasaba -5 directo a setMaxResults().
        $limit   = max(1, min((int) ($request->query->get('limit', 60)), 500));
        $records = $this->repo->findUltimos($reproductor, $limit);

        return $this->json([
            'data'  => array_map(fn(Telemetria $t) => [
                'id'                      => $t->getId(),
                'temperatura_soc_celsius' => $t->getTemperaturaSocCelsius(),
                'uso_ram_porcentaje'      => $t->getUsoRamPorcentaje(),
                'latencia_red_ms'         => $t->getLatenciaRedMs(),
                'espacio_disco_libre_mb'  => $t->getEspacioDiscoLibreMb(),
                'registrado_en'           => $t->getRegistradoEn()->format('c'),
                'alerta_temperatura'      => $t->getTemperaturaSocCelsius() >= $this->tempAlertaCelsius,
            ], $records),
            'total' => count($records),
        ]);
    }
}
