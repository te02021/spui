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
use Symfony\Component\Routing\Attribute\Route;

/**
 * Recibe telemetría del sistema enviada por los reproductores Raspberry Pi.
 * Auth: header X-Api-Key (misma que sync/heartbeat).
 */
#[Route('/api/spui/reproductores')]
class TelemetriaController extends AbstractController
{
    public function __construct(
        private readonly ReproductorAuthService $authService,
        private readonly ManagerRegistry $doctrine,
        private readonly TelemetriaRepository $repo,
        #[Autowire('%env(float:default:spui_temp_alerta_default:SPUI_TEMP_ALERTA_CELSIUS)%')]
        private readonly float $tempAlertaCelsius,
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

        $body = json_decode($request->getContent(), true) ?? [];

        if (!isset($body['uso_ram_porcentaje'], $body['espacio_disco_libre_mb'])) {
            return $this->json(
                ['error' => 'Campos requeridos: uso_ram_porcentaje, espacio_disco_libre_mb.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $temp = isset($body['temperatura_soc_celsius'])
            ? (float) $body['temperatura_soc_celsius']
            : 0.0;

        $telemetria = new Telemetria();
        $telemetria->setReproductor($reproductor);
        $telemetria->setTemperaturaSocCelsius($temp);
        $telemetria->setUsoRamPorcentaje((float) $body['uso_ram_porcentaje']);
        $telemetria->setLatenciaRedMs(isset($body['latencia_red_ms']) ? (int) $body['latencia_red_ms'] : null);
        $telemetria->setEspacioDiscoLibreMb((int) $body['espacio_disco_libre_mb']);

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

        $limit   = min((int) ($request->query->get('limit', 60)), 500);
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
