<?php

declare(strict_types=1);

namespace SPUI\Controller\Api;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\AlertaEmergencia;
use SPUI\Entity\Pantalla;
use SPUI\Entity\PlaylistItem;
use SPUI\Entity\Playlist;
use SPUI\Entity\Programacion;
use SPUI\Repository\AlertaEmergenciaRepository;
use SPUI\Repository\ProgramacionRepository;
use SPUI\Service\ReproductorAuthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints consumidos exclusivamente por los reproductores Raspberry Pi.
 * Auth: header X-Api-Key con la raw API key generada al registrar el reproductor.
 */
#[Route('/api/spui/reproductores')]
class SyncController extends AbstractController
{
    public function __construct(
        private readonly ReproductorAuthService $authService,
        private readonly ProgramacionRepository $progRepo,
        private readonly AlertaEmergenciaRepository $alertaRepo,
        private readonly ManagerRegistry $doctrine,
        private readonly RateLimiterFactory $spuiNodoSyncLimiter,
        private readonly RateLimiterFactory $spuiNodoHeartbeatLimiter,
    ) {}

    /**
     * El Pi llama a este endpoint al arrancar y cada N minutos.
     * Recibe: programación vigente por pantalla + playlist fallback + alerta activa.
     */
    #[Route('/sync', name: 'spui_reproductores_sync', methods: ['POST'])]
    public function sync(Request $request): JsonResponse
    {
        $reproductor = $this->authService->autenticar($request);
        if ($reproductor === null) {
            return $this->json(['error' => 'Autenticación requerida. Enviá X-Api-Key en el header.'], Response::HTTP_UNAUTHORIZED);
        }

        $limiter = $this->spuiNodoSyncLimiter->create('reproductor_sync_' . $reproductor->getId());
        $limit   = $limiter->consume();
        if (!$limit->isAccepted()) {
            return $this->json(
                ['error' => 'Demasiadas solicitudes de sync. Esperá antes de volver a sincronizar.'],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => $limit->getRetryAfter()->getTimestamp() - time()],
            );
        }

        $ahora = new DateTimeImmutable();

        $alerta = $this->alertaRepo->findActivaConMayorPrioridad();
        if ($alerta !== null && $alerta->haExpirado()) {
            $alerta = null;
        }

        // Para cada pantalla del reproductor, resolver programación + fallback
        $pantallasData = [];
        foreach ($reproductor->getPantallas() as $pantalla) {
            $progActiva   = $this->resolverProgramacionActiva($pantalla, $ahora);
            $playlistData = null;

            if ($progActiva !== null) {
                $playlistData = $this->serializePlaylist($progActiva->getPlaylist(), $request);
            } elseif ($pantalla->getPlaylistFallback() !== null) {
                $playlistData = $this->serializePlaylist($pantalla->getPlaylistFallback(), $request);
            }

            $pantallasData[] = [
                'id'                  => $pantalla->getId(),
                'nombre'              => $pantalla->getNombre(),
                'resolucion_ancho'    => $pantalla->getResolucionAncho(),
                'resolucion_alto'     => $pantalla->getResolucionAlto(),
                'programacion_activa' => $progActiva !== null ? $this->serializeProgMeta($progActiva) : null,
                'playlist'            => $playlistData,
            ];
        }

        return $this->json([
            'reproductor_id'    => $reproductor->getId(),
            'pantallas'         => $pantallasData,
            'alerta_emergencia' => $alerta !== null ? $this->serializeAlerta($alerta, $request) : null,
            'servidor_ts'       => $ahora->format('c'),
            'proxima_sync_seg'  => 300,
        ]);
    }

    /**
     * El Pi llama cada 60s para confirmar que sigue vivo.
     */
    #[Route('/heartbeat', name: 'spui_reproductores_heartbeat', methods: ['POST'])]
    public function heartbeat(Request $request): JsonResponse
    {
        $reproductor = $this->authService->autenticar($request);
        if ($reproductor === null) {
            return $this->json(['error' => 'Autenticación requerida. Enviá X-Api-Key en el header.'], Response::HTTP_UNAUTHORIZED);
        }

        $limiter = $this->spuiNodoHeartbeatLimiter->create('reproductor_hb_' . $reproductor->getId());
        $limit   = $limiter->consume();
        if (!$limit->isAccepted()) {
            return $this->json(
                ['error' => 'Demasiados heartbeats. El reproductor ya está registrado como activo.'],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => $limit->getRetryAfter()->getTimestamp() - time()],
            );
        }

        $reproductor->registrarHeartbeat();
        $this->doctrine->getManager('SPUI')->flush();

        return $this->json([
            'ok'          => true,
            'servidor_ts' => (new DateTimeImmutable())->format('c'),
        ]);
    }

    // -------------------------------------------------------------------------

    private function resolverProgramacionActiva(Pantalla $pantalla, DateTimeImmutable $ahora): ?Programacion
    {
        $minutos = (int) $ahora->format('H') * 60 + (int) $ahora->format('i');

        foreach ($this->progRepo->findVigentesParaPantalla($pantalla) as $prog) {
            if (!$prog->aplicaHoy()) {
                continue;
            }

            $inicio = (int) $prog->getHoraInicio()->format('H') * 60 + (int) $prog->getHoraInicio()->format('i');
            $fin    = (int) $prog->getHoraFin()->format('H') * 60 + (int) $prog->getHoraFin()->format('i');

            if ($minutos >= $inicio && $minutos < $fin) {
                return $prog;
            }
        }

        return null;
    }

    private function serializeProgMeta(Programacion $prog): array
    {
        return [
            'id'          => $prog->getId(),
            'prioridad'   => $prog->getPrioridad(),
            'hora_inicio' => $prog->getHoraInicio()->format('H:i'),
            'hora_fin'    => $prog->getHoraFin()->format('H:i'),
        ];
    }

    private function serializePlaylist(Playlist $playlist, Request $request): array
    {
        return [
            'id'     => $playlist->getId(),
            'nombre' => $playlist->getNombre(),
            'items'  => array_map(
                fn(PlaylistItem $item) => $this->serializeItem($item, $request),
                $playlist->getItems()->toArray(),
            ),
        ];
    }

    private function serializeItem(PlaylistItem $item, Request $request): array
    {
        $c = $item->getContenido();
        return [
            'orden'                  => $item->getOrden(),
            'duracion_efectiva_seg'  => $item->getDuracionEfectiva(),
            'contenido'              => [
                'id'              => $c->getId(),
                'titulo'          => $c->getTitulo(),
                'tipo'            => $c->getTipo()->value,
                'contenido_texto' => $c->getContenidoTexto(),
                'hash_archivo'    => $c->getHashArchivo(),
                'url_descarga'    => $c->getRutaArchivo() !== null
                    ? $request->getSchemeAndHttpHost() . '/api/spui/media/' . rawurlencode(basename($c->getRutaArchivo()))
                    : null,
            ],
        ];
    }

    private function serializeAlerta(AlertaEmergencia $alerta, Request $request): array
    {
        $c = $alerta->getContenido();
        return [
            'id'        => $alerta->getId(),
            'titulo'    => $alerta->getTitulo(),
            'mensaje'   => $alerta->getMensaje(),
            'prioridad' => $alerta->getPrioridad(),
            'expira_en' => $alerta->getExpiraEn()?->format('c'),
            'contenido' => $c !== null ? [
                'id'              => $c->getId(),
                'tipo'            => $c->getTipo()->value,
                'contenido_texto' => $c->getContenidoTexto(),
                'url_descarga'    => $c->getRutaArchivo() !== null
                    ? $request->getSchemeAndHttpHost() . '/api/spui/media/' . rawurlencode(basename($c->getRutaArchivo()))
                    : null,
            ] : null,
        ];
    }
}
