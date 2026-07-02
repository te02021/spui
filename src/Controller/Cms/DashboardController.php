<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Repository\AlertaEmergenciaRepository;
use SPUI\Repository\ReproductorRepository;
use SPUI\Repository\TelemetriaRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/spui')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly ReproductorRepository $reproductorRepo,
        private readonly AlertaEmergenciaRepository $alertaRepo,
        private readonly TelemetriaRepository $telemetriaRepo,
        private readonly ManagerRegistry $doctrine,
    ) {}

    #[Route('', name: 'spui_cms_dashboard')]
    public function index(): Response
    {
        $reproductores = $this->reproductorRepo->findBy([], ['id' => 'ASC']);

        // Última telemetría por reproductor
        $telemetria = [];
        foreach ($reproductores as $r) {
            $ultimos = $this->telemetriaRepo->findUltimos($r, 1);
            $telemetria[$r->getId()] = $ultimos[0] ?? null;
        }

        $alertasActivas = $this->alertaRepo->findBy(['activa' => true], ['prioridad' => 'DESC']);

        $conteo = ['conectado' => 0, 'desconectado' => 0, 'sin_registrar' => 0];
        foreach ($reproductores as $r) {
            $conteo[$r->getEstadoConexion()->value] = ($conteo[$r->getEstadoConexion()->value] ?? 0) + 1;
        }

        $umbral      = new DateTimeImmutable('-5 minutes');
        $sinActividad = array_filter(
            $reproductores,
            fn($r) => $r->getUltimoHeartbeat() !== null && $r->getUltimoHeartbeat() < $umbral,
        );

        return $this->render('@SPUI/dashboard/index.html.twig', [
            'reproductores'   => $reproductores,
            'telemetria'      => $telemetria,
            'alertas_activas' => $alertasActivas,
            'conteo'          => $conteo,
            'sin_actividad'   => count($sinActividad),
        ]);
    }
}
