<?php

declare(strict_types=1);

namespace SPUI\Command;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use PhpMqtt\Client\MqttClient;
use SPUI\Entity\Telemetria;
use SPUI\Repository\ReproductorRepository;
use SPUI\Service\MantenimientoService;
use SPUI\Service\MqttConnectionFactory;
use SPUI\Service\TelemetriaRollupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Daemon que escucha telemetría MQTT de los reproductores Pi, la persiste en
 * BD, y además hace de "scheduler" interno del proyecto con tres cadencias
 * independientes (configurables por entorno, ver services.yaml):
 *   - Alertas vencidas: cada spui_intervalo_alertas_default (10s por defecto)
 *   - Mantenimiento general (reproductores caídos, purga): cada
 *     spui_intervalo_mantenimiento_default (60s por defecto)
 *   - Rollup horario de telemetría: cada spui_intervalo_rollup_default
 *     (3600s por defecto)
 * Las alertas van más seguido que el resto porque son lo único con urgencia
 * real de UX — ver MantenimientoService::revisarAlertasVencidas().
 *
 * Por qué acá y no en cron/Programador de tareas/systemd timers: son tareas
 * periódicas de un proceso que YA tiene que estar corriendo de forma
 * continua para escuchar MQTT. Agregarlas como unidades separadas del SO
 * significaría instalar y mantener 2-3 piezas más, cada una una oportunidad
 * de olvidarse al desplegar en un servidor nuevo. Con esto, instalar este
 * único servicio (ver config/servicios/) deja las tres cosas funcionando —
 * en Windows o Linux, sin tocar cron ni Task Scheduler nunca.
 *
 * Uso:
 *   php bin/console spui:mqtt:subscribe --id=spui
 *   php bin/console spui:mqtt:subscribe --id=spui --host=192.168.1.x --port=1883
 *
 * Correr en background con systemd (Linux) o NSSM (Windows) en producción —
 * ver config/servicios/instalar-servicio.sh e instalar-servicio-telemetria.ps1.
 * Interrumpir con Ctrl+C o SIGTERM.
 */
#[AsCommand(
    name: 'spui:mqtt:subscribe',
    description: 'Escucha telemetría MQTT de los reproductores Pi, la persiste en BD, y corre el mantenimiento periódico.',
)]
class MqttSubscribeCommand extends Command
{
    private const TOPIC_TELEMETRIA = 'spui/telemetria/+';

    /**
     * Aviso de conectado/desconectado publicado por cada Pi — el "online" al
     * conectarse, y el "offline" lo publica el BROKER en nombre de la Pi
     * (Last Will) si la conexión se corta de golpe. Ver tarea 1.3 y
     * Reproductor::estadoCalculado().
     */
    // Ojo: 'spui/estado/reproductor/{id}' tiene 4 niveles, no 3 como
    // spui/telemetria/{id} — '+' es comodín de un solo nivel, así que el
    // filtro tiene que llevar el segmento 'reproductor' explícito o no
    // matchea nada (bug real, encontrado probando: la suscripción se
    // aceptaba sin error y el mensaje retenido nunca llegaba).
    private const TOPIC_ESTADO = 'spui/estado/reproductor/+';

    private ?SymfonyStyle $io = null;

    /** Segundos de loop transcurridos en los que toca la próxima revisión de alertas. */
    private float $proximaRevisionAlertas = 0.0;

    /** Segundos de loop transcurridos en los que toca el próximo mantenimiento general. */
    private float $proximoMantenimiento = 0.0;

    /** Segundos de loop transcurridos en los que toca el próximo rollup. */
    private float $proximoRollup = 0.0;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ReproductorRepository $reproductorRepo,
        private readonly MantenimientoService $mantenimiento,
        private readonly TelemetriaRollupService $rollup,
        private readonly MqttConnectionFactory $mqttConnection,
        #[Autowire('%env(float:default:spui_temp_alerta_default:SPUI_TEMP_ALERTA_CELSIUS)%')]
        private readonly float $tempAlertaCelsius,
        #[Autowire('%env(int:default:spui_umbral_conexion_default:SPUI_UMBRAL_CONEXION_SEG)%')]
        private readonly int $umbralConexionSeg,
        // Los tres a continuación son cada cuánto se dispara cada tarea
        // periódica interna del daemon — ver services.yaml para el porqué de
        // cada default. Configurables por entorno para no tener que tocar
        // código si algún día hace falta afinarlos.
        #[Autowire('%env(int:default:spui_intervalo_alertas_default:SPUI_INTERVALO_ALERTAS_SEG)%')]
        private readonly int $intervaloAlertasSeg,
        #[Autowire('%env(int:default:spui_intervalo_mantenimiento_default:SPUI_INTERVALO_MANTENIMIENTO_SEG)%')]
        private readonly int $intervaloMantenimientoSeg,
        #[Autowire('%env(int:default:spui_intervalo_rollup_default:SPUI_INTERVALO_ROLLUP_SEG)%')]
        private readonly int $intervaloRollupSeg,
        #[Autowire('%env(string:MQTT_USER)%')]
        private readonly string $mqttUser,
        #[Autowire('%env(string:MQTT_PASS)%')]
        private readonly string $mqttPass,
        #[Autowire('%env(string:MQTT_HOST)%')]
        private readonly string $mqttHost = '127.0.0.1',
        #[Autowire('%env(int:MQTT_PORT)%')]
        private readonly int $mqttPort = 1883,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        // El default de --host/--port sale de MQTT_HOST/MQTT_PORT (mismo env
        // que usan AlertaPublisherService, ComandoPublisherService y
        // MqttSecurityService) en vez de un literal fijo — antes este
        // comando era el único de los cuatro que no seguía esas variables:
        // servido como servicio (sin --host/--port explícitos), quedaba
        // pegado a 127.0.0.1:1883 pasara lo que pasara con el entorno. Con
        // TLS (tarea 1.0.e) eso significaba un cliente TLS conectando contra
        // el listener sin cifrar — falla, pero con un error genérico que no
        // dice por qué. --host/--port siguen pudiéndose pisar a mano para
        // probar contra otro broker sin tocar el entorno.
        $this
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'MQTT broker host', $this->mqttHost)
            ->addOption('port', null, InputOption::VALUE_OPTIONAL, 'MQTT broker port', (string) $this->mqttPort);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $this->io = $io;
        $host     = (string) $input->getOption('host');
        $port     = (int) $input->getOption('port');

        $io->title('SPUI — MQTT Telemetría Subscriber');
        $io->info(sprintf('Broker: %s:%d | Topic: %s', $host, $port, self::TOPIC_TELEMETRIA));

        $settings = $this->mqttConnection
            ->paraCredenciales($this->mqttUser, $this->mqttPass)
            ->setKeepAliveInterval(60)
            ->setConnectTimeout(5);

        $mqtt = new MqttClient($host, $port, 'spui-cms-' . gethostname());

        try {
            $mqtt->connect($settings);
        } catch (\Throwable $e) {
            $io->error('No se pudo conectar al broker MQTT: ' . $e->getMessage());

            // El broker rechaza la conexión con el mismo error genérico esté
            // caído o sean las credenciales las que no sirven, así que conviene
            // nombrar las dos causas: sin esta pista, un usuario mal
            // configurado se diagnostica como "Mosquitto no está corriendo".
            $io->note(sprintf(
                'Verificá que Mosquitto esté corriendo en %s:%d y que MQTT_USER/MQTT_PASS '
                . 'coincidan con el cliente creado en el broker (usuario actual: %s). '
                . 'Ver apps/spui/config/mosquitto/README.md',
                $host,
                $port,
                $this->mqttUser !== '' ? $this->mqttUser : '(vacío)',
            ));

            return Command::FAILURE;
        }

        $io->success('Conectado. Esperando telemetría de reproductores Pi...');

        $mqtt->subscribe(
            self::TOPIC_TELEMETRIA,
            function (string $topic, string $message) use ($io): void {
                $this->procesarMensaje($topic, $message, $io);
            },
            0,
        );

        $mqtt->subscribe(
            self::TOPIC_ESTADO,
            function (string $topic, string $message) use ($io): void {
                $this->procesarEstado($topic, $message, $io);
            },
            1,
        );

        // Corrida inicial al arrancar: si el proceso estuvo caído (o es la
        // primera vez que se instala), no hay que esperar hasta el próximo
        // intervalo para que alertas/mantenimiento/rollup se pongan al día.
        // Mismo espíritu que Persistent=true en un systemd timer.
        $this->tick($io);
        $this->proximaRevisionAlertas = $this->intervaloAlertasSeg;
        $this->proximoMantenimiento   = $this->intervaloMantenimientoSeg;
        $this->proximoRollup          = $this->intervaloRollupSeg;

        $mqtt->registerLoopEventHandler(
            function (MqttClient $mqtt, float $elapsedTime) use ($io): void {
                if (
                    $elapsedTime < $this->proximaRevisionAlertas
                    && $elapsedTime < $this->proximoMantenimiento
                    && $elapsedTime < $this->proximoRollup
                ) {
                    return;
                }
                $this->tick($io, $elapsedTime);
            },
        );

        // Bucle bloqueante — el broker envía pings para mantener la conexión viva.
        // Interrumpir con Ctrl+C o SIGTERM.
        $mqtt->loop(true);

        return Command::SUCCESS;
    }

    /**
     * Corre revisión de alertas, rollup y mantenimiento general si a cada
     * uno le toca, en ese orden — el rollup tiene que agregar las lecturas
     * antes de que el mantenimiento general pueda purgarlas (la revisión de
     * alertas no toca telemetría, así que su orden relativo no importa).
     * Nunca deja morir el daemon: un fallo acá no puede cortar la ingesta de
     * telemetría, que es la razón de ser del proceso.
     */
    private function tick(SymfonyStyle $io, ?float $elapsedTime = null): void
    {
        // $elapsedTime === null: es la corrida inicial al arrancar, antes de
        // que el loop event handler exista. Las tres tareas corren igual.
        $tocaAlertas       = $elapsedTime === null || $elapsedTime >= $this->proximaRevisionAlertas;
        $tocaRollup        = $elapsedTime === null || $elapsedTime >= $this->proximoRollup;
        $tocaMantenimiento = $elapsedTime === null || $elapsedTime >= $this->proximoMantenimiento;

        if ($tocaAlertas) {
            try {
                $expiradas = $this->mantenimiento->revisarAlertasVencidas();
                if ($expiradas !== []) {
                    $io->writeln(sprintf(
                        '[%s] Alertas: %d vencida(s) desactivada(s).',
                        $this->ts(),
                        count($expiradas),
                    ));
                }
                $this->doctrine->getManager('SPUI')->clear();
            } catch (\Throwable $e) {
                $io->warning(sprintf('[%s] Revisión de alertas vencidas falló: %s', $this->ts(), $e->getMessage()));
            }
            if ($elapsedTime !== null) {
                $this->proximaRevisionAlertas = $elapsedTime + $this->intervaloAlertasSeg;
            }
        }

        if ($tocaRollup) {
            try {
                $resultado = $this->rollup->ejecutar();
                if ($resultado['desde'] !== null) {
                    $io->writeln(sprintf(
                        '[%s] Rollup de telemetría: %d fila(s) afectada(s) desde %s.',
                        $this->ts(),
                        $resultado['filas_afectadas'],
                        $resultado['desde']->format('Y-m-d H:i'),
                    ));
                }
                $this->doctrine->getManager('SPUI')->clear();
            } catch (\Throwable $e) {
                $io->warning(sprintf('[%s] Rollup de telemetría falló: %s', $this->ts(), $e->getMessage()));
            }
            if ($elapsedTime !== null) {
                $this->proximoRollup = $elapsedTime + $this->intervaloRollupSeg;
            }
        }

        if ($tocaMantenimiento) {
            try {
                $resultado = $this->mantenimiento->ejecutarGeneral(
                    $this->umbralConexionSeg,
                    MantenimientoService::RETENCION_DIAS_DEFAULT,
                );
                if ($resultado['caidos'] !== [] || $resultado['telemetria_purgada'] > 0) {
                    $io->writeln(sprintf(
                        '[%s] Mantenimiento: %d reproductor(es) desconectado(s), %d fila(s) de telemetría purgada(s).',
                        $this->ts(),
                        count($resultado['caidos']),
                        $resultado['telemetria_purgada'],
                    ));
                }
                $this->doctrine->getManager('SPUI')->clear();
            } catch (\Throwable $e) {
                $io->warning(sprintf('[%s] Mantenimiento falló: %s', $this->ts(), $e->getMessage()));
            }
            if ($elapsedTime !== null) {
                $this->proximoMantenimiento = $elapsedTime + $this->intervaloMantenimientoSeg;
            }
        }
    }

    private function procesarMensaje(string $topic, string $message, SymfonyStyle $io): void
    {
        $data = json_decode($message, true);
        if (!is_array($data)) {
            $io->warning(sprintf('[%s] Mensaje JSON inválido en %s', $this->ts(), $topic));
            return;
        }

        // Extraer reproductor_id desde el topic 'spui/telemetria/{reproductor_id}'
        $partes        = explode('/', $topic);
        $reproductorId = (int) end($partes);
        $reproductor   = $this->reproductorRepo->find($reproductorId);

        if ($reproductor === null) {
            $io->warning(sprintf('[%s] Reproductor %d no encontrado (topic: %s).', $this->ts(), $reproductorId, $topic));
            return;
        }

        $temp = isset($data['temperatura_soc_celsius'])
            ? (float) $data['temperatura_soc_celsius']
            : 0.0;

        $telemetria = new Telemetria();
        $telemetria->setReproductor($reproductor);
        $telemetria->setTemperaturaSocCelsius($temp);
        $telemetria->setUsoRamPorcentaje((float) ($data['uso_ram_porcentaje'] ?? 0.0));
        $telemetria->setLatenciaRedMs(isset($data['latencia_red_ms']) ? (int) $data['latencia_red_ms'] : null);
        $telemetria->setEspacioDiscoLibreMb((int) ($data['espacio_disco_libre_mb'] ?? 0));

        $em = $this->doctrine->getManager('SPUI');
        $em->persist($telemetria);
        $em->flush();
        // Limpiar el Identity Map para evitar memory leak en daemon de larga duración
        $em->clear();

        $io->writeln(sprintf(
            '[%s] Reproductor <info>%d</info> — temp=<comment>%.1f°C</comment>  ram=<comment>%.1f%%</comment>  disco=<comment>%dMB</comment>',
            $this->ts(),
            $reproductorId,
            $temp,
            (float) ($data['uso_ram_porcentaje'] ?? 0),
            (int) ($data['espacio_disco_libre_mb'] ?? 0),
        ));

        if ($temp >= $this->tempAlertaCelsius) {
            $io->caution(sprintf(
                'TEMPERATURA CRÍTICA en Reproductor %d: %.1f°C (umbral: %.0f°C)',
                $reproductorId,
                $temp,
                $this->tempAlertaCelsius,
            ));
        }
    }

    /**
     * Procesa el topic spui/estado/{reproductor_id} — tarea 1.3 (LWT).
     *
     * "offline" llega de dos formas indistinguibles acá, y no hace falta
     * distinguirlas: publicado por la propia Pi al perder la conexión de
     * forma prolija (no debería pasar, pero no cuesta nada cubrirlo), o por
     * el BROKER en su nombre si la conexión se cortó de golpe (el caso real
     * que motiva esto — ver mqtt_listener.py::will_set en el cliente).
     */
    private function procesarEstado(string $topic, string $message, SymfonyStyle $io): void
    {
        $data = json_decode($message, true);
        if (!is_array($data) || !isset($data['estado'])) {
            $io->warning(sprintf('[%s] Mensaje de estado inválido en %s', $this->ts(), $topic));
            return;
        }

        $partes        = explode('/', $topic);
        $reproductorId = (int) end($partes);
        $reproductor   = $this->reproductorRepo->find($reproductorId);

        if ($reproductor === null) {
            $io->warning(sprintf('[%s] Reproductor %d no encontrado (topic: %s).', $this->ts(), $reproductorId, $topic));
            return;
        }

        if ($data['estado'] === 'offline') {
            $reproductor->marcarLwtOffline();
            $io->writeln(sprintf('[%s] Reproductor <error>%d</error> — LWT: caída detectada por el broker.', $this->ts(), $reproductorId));
        } elseif ($data['estado'] === 'online') {
            // La Pi publica esto apenas reconecta (mqtt_listener.py::_on_connect),
            // sin esperar su próximo heartbeat HTTP programado — puede ahorrar
            // hasta 60s de demora. Se trata como una prueba de vida más, igual
            // que un heartbeat HTTP (mismo método, ver su docblock).
            $reproductor->registrarHeartbeat();
            $io->writeln(sprintf('[%s] Reproductor <info>%d</info> — LWT: conectado.', $this->ts(), $reproductorId));
        } else {
            $io->warning(sprintf('[%s] Estado desconocido "%s" en %s', $this->ts(), $data['estado'], $topic));
            return;
        }

        $em = $this->doctrine->getManager('SPUI');
        $em->flush();
        $em->clear();
    }

    private function ts(): string
    {
        return (new DateTimeImmutable())->format('H:i:s');
    }
}
