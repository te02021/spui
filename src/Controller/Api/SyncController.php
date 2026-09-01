<?php

declare(strict_types=1);

namespace SPUI\Controller\Api;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use SPUI\Entity\AlertaEmergencia;
use SPUI\Entity\CronogramaItem;
use SPUI\Entity\Pantalla;
use SPUI\Entity\PlaylistItem;
use SPUI\Entity\Playlist;
use SPUI\Entity\Programacion;
use SPUI\Entity\ProgramacionEnergetica;
use SPUI\Enum\TipoContenido;
use SPUI\Repository\AlertaEmergenciaRepository;
use SPUI\Repository\CronogramaItemRepository;
use SPUI\Repository\ProgramacionEnergeticaRepository;
use SPUI\Repository\ProgramacionRepository;
use SPUI\Service\MediaStorageService;
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
        private readonly CronogramaItemRepository $cronogramaItemRepo,
        private readonly ProgramacionEnergeticaRepository $energiaRepo,
        private readonly ManagerRegistry $doctrine,
        private readonly MediaStorageService $media,
        private readonly RateLimiterFactory $spuiReproductorSyncLimiter,
        private readonly RateLimiterFactory $spuiReproductorHeartbeatLimiter,
        private readonly LoggerInterface $logger,
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

        $limiter = $this->spuiReproductorSyncLimiter->create('reproductor_sync_' . $reproductor->getId());
        $limit   = $limiter->consume();
        if (!$limit->isAccepted()) {
            return $this->json(
                ['error' => 'Demasiadas solicitudes de sync. Esperá antes de volver a sincronizar.'],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => $limit->getRetryAfter()->getTimestamp() - time()],
            );
        }

        $ahora = new DateTimeImmutable();

        // La expiración ya se filtra en la consulta; este chequeo queda como
        // red de seguridad por si la alerta venció entre la consulta y acá.
        $alerta = $this->alertaRepo->findActivaConMayorPrioridad();
        if ($alerta !== null && $alerta->haExpirado()) {
            $alerta = null;
        }

        // Una alerta dirigida sólo se le entrega al reproductor si alguna de
        // sus pantallas está entre las destinatarias. Las globales van a todos.
        if ($alerta !== null && !$alerta->esGlobal()) {
            $esDestinatario = false;
            foreach ($reproductor->getPantallas() as $pantalla) {
                if ($alerta->getPantallas()->contains($pantalla)) {
                    $esDestinatario = true;
                    break;
                }
            }
            if (!$esDestinatario) {
                $alerta = null;
            }
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
                // CU-11: horario energético semanal. Lista vacía = sin gestión de
                // energía; el nodo deja la pantalla siempre encendida.
                'energia'             => $this->serializeEnergia($pantalla),
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

        $limiter = $this->spuiReproductorHeartbeatLimiter->create('reproductor_hb_' . $reproductor->getId());
        $limit   = $limiter->consume();
        if (!$limit->isAccepted()) {
            return $this->json(
                ['error' => 'Demasiados heartbeats. El reproductor ya está registrado como activo.'],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => $limit->getRetryAfter()->getTimestamp() - time()],
            );
        }

        $reproductor->registrarHeartbeat();

        // El reproductor informa acá los problemas que detecta por su cuenta
        // (por ejemplo, que no encuentra el entorno gráfico y no puede mostrar
        // nada en pantalla). Se guardan para que el operador los vea en el
        // panel: un equipo puede estar conectado y sincronizando y aun así no
        // estar mostrando nada, y eso no puede pasar inadvertido.
        $body = json_decode($request->getContent() ?: '[]', true);
        if (is_array($body) && array_key_exists('diagnostico', $body)) {
            $problemas = is_array($body['diagnostico']) ? $body['diagnostico'] : [];
            // Se acotan longitud y cantidad: el texto viene de un cliente y
            // termina renderizado en el panel.
            $problemas = array_slice(
                array_map(fn($p) => mb_substr((string) $p, 0, 300), $problemas),
                0,
                10,
            );
            $reproductor->setDiagnostico($problemas);

            if ($problemas !== []) {
                $this->logger->warning(
                    'SPUI: el reproductor {hostname} reporta {n} problema(s): {detalle}',
                    [
                        'hostname' => $reproductor->getHostname(),
                        'n'        => count($problemas),
                        'detalle'  => implode(' | ', $problemas),
                    ],
                );
            }
        }

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

    /**
     * Horario energético de la pantalla, un elemento por día configurado.
     *
     * dia_semana va en ISO (1=Lunes … 7=Domingo), igual que date('N') en PHP y
     * que isoweekday() en Python, para que el nodo no tenga que convertir nada.
     * Ojo: es una convención distinta a la bitmask (bit0=Lunes) que usan
     * Programacion y CronogramaItem.
     */
    private function serializeEnergia(Pantalla $pantalla): array
    {
        $reglas = $this->energiaRepo->findByPantallaOrdenado($pantalla);

        // Las horas son nullable en la entidad (los setters aceptan null para
        // que el formulario pueda mapear antes de validar). Una fila incompleta
        // haría reventar ->format() y el sync entero devolvería un 500: ese
        // reproductor se quedaría con su caché para siempre, sin poder
        // actualizarse nunca más. Se descarta la regla y se sigue.
        $completas = array_filter(
            $reglas,
            static function (ProgramacionEnergetica $pe): bool {
                return $pe->getHoraEncendido() !== null && $pe->getHoraApagado() !== null;
            },
        );

        $descartadas = count($reglas) - count($completas);
        if ($descartadas > 0) {
            $this->logger->warning(
                'SPUI: {n} regla(s) de energía sin horario completo en la pantalla {pantalla} — se omiten del sync.',
                ['n' => $descartadas, 'pantalla' => $pantalla->getId()],
            );
        }

        return array_values(array_map(
            fn(ProgramacionEnergetica $pe) => [
                'dia_semana'     => $pe->getDiaSemana(),
                'hora_encendido' => $pe->getHoraEncendido()->format('H:i'),
                'hora_apagado'   => $pe->getHoraApagado()->format('H:i'),
                'nivel_brillo'   => $pe->getNivelBrillo(),
            ],
            $completas,
        ));
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

        $cronogramaItems = null;
        if ($c->getTipo() === TipoContenido::Cronograma) {
            $bit  = (int)(new DateTimeImmutable())->format('N') - 1; // 0=Lun … 6=Dom
            $mask = 1 << $bit;
            $cronogramaItems = array_values(array_map(
                fn(CronogramaItem $ci) => [
                    'nombre'      => $ci->getNombre(),
                    'aula'        => $ci->getAula(),
                    'hora_inicio' => $ci->getHoraInicio()->format('H:i'),
                    'hora_fin'    => $ci->getHoraFin()->format('H:i'),
                ],
                array_filter(
                    $this->cronogramaItemRepo->findByContenidoOrdenado($c),
                    fn(CronogramaItem $ci) => (bool)($ci->getDiasSemana() & $mask),
                ),
            ));
        }

        return [
            'orden'                 => $item->getOrden(),
            'duracion_efectiva_seg' => $item->getDuracionEfectiva(),
            'contenido'             => [
                'id'               => $c->getId(),
                'titulo'           => $c->getTitulo(),
                'tipo'             => $c->getTipo()->value,
                'contenido_texto'  => $c->getContenidoTexto(),
                'hash_archivo'     => $c->getHashArchivo(),
                'url_descarga'     => $this->media->rutaParaUrl($c->getRutaArchivo()) !== null
                    ? $request->getSchemeAndHttpHost() . '/api/spui/media/' . $this->media->rutaParaUrl($c->getRutaArchivo())
                    : null,
                'cronograma_items' => $cronogramaItems,
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
            'sonido_url'  => $this->media->rutaParaUrl($alerta->getSonidoArchivo()) !== null
                ? $request->getSchemeAndHttpHost() . '/api/spui/media/' . $this->media->rutaParaUrl($alerta->getSonidoArchivo())
                : null,
            'sonido_hash' => $alerta->getSonidoHashArchivo(),
            // Mismas claves planas que publica AlertaPublisherService por MQTT
            // (ver el docblock ahí) — Player.mostrar_alerta() del pi-client usa
            // sólo estas tres, sea cual sea el canal por el que llegó la alerta.
            // 'contenido' (el objeto anidado de abajo) queda además por si algo
            // más necesita la forma completa (contenido_texto, id, etc.).
            'contenido_tipo' => $c?->getTipo()->value,
            'contenido_url'  => $c !== null && $this->media->rutaParaUrl($c->getRutaArchivo()) !== null
                ? $request->getSchemeAndHttpHost() . '/api/spui/media/' . $this->media->rutaParaUrl($c->getRutaArchivo())
                : null,
            'contenido_hash' => $c?->getHashArchivo(),
            'contenido' => $c !== null ? [
                'id'              => $c->getId(),
                'tipo'            => $c->getTipo()->value,
                'contenido_texto' => $c->getContenidoTexto(),
                'url_descarga'    => $this->media->rutaParaUrl($c->getRutaArchivo()) !== null
                    ? $request->getSchemeAndHttpHost() . '/api/spui/media/' . $this->media->rutaParaUrl($c->getRutaArchivo())
                    : null,
            ] : null,
        ];
    }
}
