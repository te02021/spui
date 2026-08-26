<?php

declare(strict_types=1);

namespace SPUI\Service;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Enum\EstadoConexion;
use SPUI\Repository\AlertaEmergenciaRepository;
use SPUI\Repository\ReproductorRepository;
use SPUI\Repository\TelemetriaRepository;

/**
 * Tareas periódicas de mantenimiento de la red de reproductores: reconciliar
 * el estado de conexión persistido, purgar telemetría vieja y desactivar
 * alertas vencidas.
 *
 * Extraído de MantenimientoCommand para que tanto el comando de consola (uso
 * manual/diagnóstico, con --dry-run) como el daemon spui:mqtt:subscribe
 * (ejecución periódica automática, ver MqttSubscribeCommand) llamen a la
 * misma lógica sin duplicarla.
 *
 * Expuesto en dos métodos con cadencias distintas a propósito —
 * ejecutarGeneral() y revisarAlertasVencidas() — porque no todas estas
 * tareas tienen la misma urgencia; ver el docblock de cada una.
 */
final class MantenimientoService
{
    /**
     * Días de telemetría CRUDA a conservar.
     *
     * Bajó de 90 a 7 al incorporarse telemetria_hora: el histórico largo vive
     * en el rollup, que ocupa 1/60 y es lo que los gráficos consumen para los
     * rangos de 7 y 30 días. El crudo sólo hace falta para el detalle de las
     * últimas 24 h, así que 7 días deja margen de sobra.
     *
     * IMPORTANTE: el rollup (TelemetriaRollupService) tiene que correr ANTES
     * que la purga. Si la purga se adelanta, borra lecturas que aún no
     * fueron agregadas y esas horas se pierden del histórico. El daemon
     * respeta ese orden (ver MqttSubscribeCommand::tick()); si se invoca este
     * servicio manualmente, es responsabilidad de quien lo llama.
     */
    public const RETENCION_DIAS_DEFAULT = 7;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ReproductorRepository $reproductorRepo,
        private readonly TelemetriaRepository $telemetriaRepo,
        private readonly AlertaEmergenciaRepository $alertaRepo,
        private readonly AlertaPublisherService $alertaPublisher,
    ) {
    }

    /**
     * Reproductores caídos + purga de telemetría vieja.
     *
     * Separado de revisarAlertasVencidas() porque esto no tiene urgencia de
     * UX (ver el comentario de spui_intervalo_mantenimiento_default en
     * services.yaml) — puede correr cada 60s o más sin que nadie lo note.
     *
     * @return array{
     *     caidos: list<array{hostname: string, id: int, ultimo_heartbeat: ?string}>,
     *     telemetria_purgada: int,
     *     telemetria_purga_deshabilitada: bool,
     * }
     */
    public function ejecutarGeneral(int $segundosUmbral, int $retencionDias, bool $dryRun = false): array
    {
        $em = $this->doctrine->getManager('SPUI');

        // ── Reproductores caídos ─────────────────────────────────────────────
        //
        // Desde que el panel calcula el estado con Reproductor::estadoCalculado(),
        // esto ya no es lo que hace que un equipo caído se vea como tal: la
        // pantalla dice la verdad aunque este servicio no corra nunca. Se
        // mantiene para que la columna estado_conexion quede coherente con lo
        // que se muestra, porque la lee la API REST y cualquier consulta SQL
        // directa.
        $limite = new DateTimeImmutable(sprintf('-%d seconds', $segundosUmbral));
        $caidos = $this->reproductorRepo->findConectadosSinHeartbeatDesde($limite);

        $caidosInfo = [];
        foreach ($caidos as $reproductor) {
            $caidosInfo[] = [
                'hostname'         => $reproductor->getHostname(),
                'id'               => $reproductor->getId(),
                'ultimo_heartbeat' => $reproductor->getUltimoHeartbeat()?->format('Y-m-d H:i:s'),
            ];
            if (!$dryRun) {
                $reproductor->setEstadoConexion(EstadoConexion::Desconectado);
            }
        }
        if (!$dryRun && $caidosInfo !== []) {
            $em->flush();
        }

        // ── Purga de telemetría ──────────────────────────────────────────────
        $telemetriaPurgada = 0;
        $purgaDeshabilitada = $retencionDias === 0;
        if (!$purgaDeshabilitada && !$dryRun) {
            $corte = new DateTimeImmutable(sprintf('-%d days', $retencionDias));
            $telemetriaPurgada = $this->telemetriaRepo->purgarAnterioresA($corte);
        }

        return [
            'caidos'                        => $caidosInfo,
            'telemetria_purgada'             => $telemetriaPurgada,
            'telemetria_purga_deshabilitada' => $purgaDeshabilitada,
        ];
    }

    /**
     * Desactiva las alertas de emergencia vencidas y empuja la baja por MQTT.
     *
     * Separado de ejecutarGeneral() porque acá sí importa la latencia: una
     * alerta vencida que sigue interrumpiendo pantallas se nota. Pensado para
     * correr cada pocos segundos (spui_intervalo_alertas_default) — es una
     * consulta indexada sobre una tabla chica, no un problema correrla seguido.
     *
     * @return list<array{titulo: string, id: int, expiraba: ?string}>
     */
    public function revisarAlertasVencidas(bool $dryRun = false): array
    {
        // Nada las desactivaba antes de esto: quedaban con activa = 1 para
        // siempre, seguían saliendo como vigentes en el dashboard y su
        // mensaje MQTT retenido se le entregaba a cualquier Pi que se
        // reconectara.
        $expiradas = $this->alertaRepo->findActivasExpiradas();

        $expiradasInfo = [];
        foreach ($expiradas as $alerta) {
            $expiradasInfo[] = [
                'titulo'   => $alerta->getTitulo(),
                'id'       => $alerta->getId(),
                'expiraba' => $alerta->getExpiraEn()?->format('Y-m-d H:i'),
            ];
            if (!$dryRun) {
                $alerta->desactivar();
            }
        }
        if (!$dryRun && $expiradasInfo !== []) {
            $this->doctrine->getManager('SPUI')->flush();
            // Recién después del flush: si la publicación falla, la alerta
            // igual quedó desactivada en la base.
            foreach ($expiradas as $alerta) {
                $this->alertaPublisher->publicarDesactivacion($alerta);
            }
        }

        return $expiradasInfo;
    }
}
