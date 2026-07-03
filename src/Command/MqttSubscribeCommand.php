<?php

declare(strict_types=1);

namespace SPUI\Command;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use SPUI\Entity\Telemetria;
use SPUI\Repository\ReproductorRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Daemon que escucha telemetría MQTT de los reproductores Pi y la persiste en BD.
 *
 * Uso:
 *   php bin/console spui:mqtt:subscribe --id=spui
 *   php bin/console spui:mqtt:subscribe --id=spui --host=192.168.1.x --port=1883
 *
 * Correr en background con systemd o supervisor en producción.
 * Interrumpir con Ctrl+C o SIGTERM.
 */
#[AsCommand(
    name: 'spui:mqtt:subscribe',
    description: 'Escucha telemetría MQTT de los reproductores Pi y la persiste en BD.',
)]
class MqttSubscribeCommand extends Command
{
    private const TEMP_ALERTA_CELSIUS = 70.0;
    private const TOPIC_TELEMETRIA    = 'spui/telemetria/+';

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ReproductorRepository $reproductorRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'MQTT broker host', '127.0.0.1')
            ->addOption('port', null, InputOption::VALUE_OPTIONAL, 'MQTT broker port', '1883');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $host = (string) $input->getOption('host');
        $port = (int) $input->getOption('port');

        $io->title('SPUI — MQTT Telemetría Subscriber');
        $io->info(sprintf('Broker: %s:%d | Topic: %s', $host, $port, self::TOPIC_TELEMETRIA));

        $settings = (new ConnectionSettings())
            ->setKeepAliveInterval(60)
            ->setConnectTimeout(5);

        $mqtt = new MqttClient($host, $port, 'spui-cms-' . gethostname());

        try {
            $mqtt->connect($settings);
        } catch (\Throwable $e) {
            $io->error('No se pudo conectar al broker MQTT: ' . $e->getMessage());
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

        // Bucle bloqueante — el broker envía pings para mantener la conexión viva.
        // Interrumpir con Ctrl+C o SIGTERM.
        $mqtt->loop(true);

        return Command::SUCCESS;
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

        if ($temp >= self::TEMP_ALERTA_CELSIUS) {
            $io->caution(sprintf(
                'TEMPERATURA CRÍTICA en Reproductor %d: %.1f°C (umbral: %.0f°C)',
                $reproductorId,
                $temp,
                self::TEMP_ALERTA_CELSIUS,
            ));
        }
    }

    private function ts(): string
    {
        return (new DateTimeImmutable())->format('H:i:s');
    }
}
