<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Pantalla;
use SPUI\Entity\ProgramacionEnergetica;
use SPUI\Form\ProgramacionEnergeticaType;
use SPUI\Repository\PantallaRepository;
use SPUI\Repository\ProgramacionEnergeticaRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * CU-11 — Programación energética: encendido, apagado y brillo por día.
 *
 * Cuelga de la ficha de cada pantalla en vez de ser una sección propia del menú:
 * el horario energético no tiene sentido fuera de la pantalla a la que aplica.
 * Hay como máximo una regla por pantalla y día (unique uq_pantalla_dia), así que
 * la grilla siempre muestra los 7 días y cada uno se crea o edita en el lugar.
 */
#[Route('/spui/pantallas/{id}/energia', requirements: ['id' => '\d+'])]
class ProgramacionEnergeticaCmsController extends AbstractController
{
    /** Nombres de los días indexados por su valor ISO (1=Lunes … 7=Domingo). */
    public const DIAS = [
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sábado',
        7 => 'Domingo',
    ];

    public function __construct(
        private readonly PantallaRepository $pantallaRepo,
        private readonly ProgramacionEnergeticaRepository $repo,
        private readonly ManagerRegistry $doctrine,
    ) {}

    private function em()
    {
        return $this->doctrine->getManager('SPUI');
    }

    private function getPantallaOr404(int $id): Pantalla
    {
        $pantalla = $this->pantallaRepo->find($id);
        if (!$pantalla) {
            throw $this->createNotFoundException('Pantalla no encontrada.');
        }

        return $pantalla;
    }

    /** Grilla semanal: los 7 días, con o sin regla cargada. */
    #[Route('', name: 'spui_cms_energia_grilla', methods: ['GET'])]
    public function grilla(int $id): Response
    {
        $pantalla = $this->getPantallaOr404($id);

        return $this->render('@SPUI/energia/grilla.html.twig', [
            'pantalla' => $pantalla,
            'reglas'   => $this->repo->findByPantallaIndexadoPorDia($pantalla),
            'dias'     => self::DIAS,
        ]);
    }

    /**
     * Crea o edita la regla de un día. Es una sola acción porque desde la grilla
     * el usuario no distingue "crear" de "editar": completa la fila del día.
     */
    #[Route('/{dia}', name: 'spui_cms_energia_editar', methods: ['GET', 'POST'], requirements: ['dia' => '[1-7]'])]
    public function editar(int $id, int $dia, Request $request): Response
    {
        $pantalla = $this->getPantallaOr404($id);

        $regla = $this->repo->findOneBy(['pantalla' => $pantalla, 'diaSemana' => $dia]);
        $esNueva = $regla === null;

        if ($esNueva) {
            $regla = new ProgramacionEnergetica();
            $regla->setPantalla($pantalla);
            $regla->setDiaSemana($dia);
            // Valores de arranque razonables para una cartelera de campus
            $regla->setHoraEncendido(new DateTimeImmutable('07:30'));
            $regla->setHoraApagado(new DateTimeImmutable('21:00'));
            $regla->setNivelBrillo(100);
        }

        $form = $this->createForm(ProgramacionEnergeticaType::class, $regla, [
            'action' => $this->generateUrl('spui_cms_energia_editar', ['id' => $id, 'dia' => $dia]),
        ]);
        $form->handleRequest($request);

        $grillaUrl = $this->generateUrl('spui_cms_energia_grilla', ['id' => $id]);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($regla->getHoraApagado() <= $regla->getHoraEncendido()) {
                $msg = 'La hora de apagado tiene que ser posterior a la de encendido.';
                if ($request->isXmlHttpRequest()) {
                    return $this->json(['success' => false, 'message' => $msg, 'type' => 'warning'], 422);
                }
                $this->addFlash('warning', $msg);
                return $this->redirect($grillaUrl);
            }

            if ($esNueva) {
                $this->em()->persist($regla);
            }
            $this->em()->flush();

            $msg = 'Horario de ' . self::DIAS[$dia] . ' guardado.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'message' => $msg, 'redirect' => $grillaUrl]);
            }
            $this->addFlash('success', $msg);
            return $this->redirect($grillaUrl);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => self::DIAS[$dia] . ' — ' . $pantalla->getNombre(),
                'html'  => $this->renderView('@SPUI/energia/_form.html.twig', [
                    'form'     => $form,
                    'pantalla' => $pantalla,
                    'dia'      => self::DIAS[$dia],
                    'esNueva'  => $esNueva,
                ]),
            ]);
        }

        return $this->redirect($grillaUrl);
    }

    /** Quita la regla de un día: la pantalla queda sin gestión energética ese día. */
    #[Route('/{dia}/eliminar', name: 'spui_cms_energia_eliminar', methods: ['POST'], requirements: ['dia' => '[1-7]'])]
    public function eliminar(int $id, int $dia, Request $request): Response
    {
        $pantalla = $this->getPantallaOr404($id);
        $regla    = $this->repo->findOneBy(['pantalla' => $pantalla, 'diaSemana' => $dia]);

        $grillaUrl = $this->generateUrl('spui_cms_energia_grilla', ['id' => $id]);

        if (!$regla) {
            $msg = 'Ese día no tiene horario configurado.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => $msg, 'type' => 'warning'], 404);
            }
            $this->addFlash('warning', $msg);
            return $this->redirect($grillaUrl);
        }

        $this->em()->remove($regla);
        $this->em()->flush();

        $msg = 'Horario de ' . self::DIAS[$dia] . ' eliminado.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg, 'redirect' => $grillaUrl]);
        }
        $this->addFlash('success', $msg);
        return $this->redirect($grillaUrl);
    }

    /**
     * Copia el horario de un día a los demás días hábiles (lunes a viernes).
     * Es el caso más común: todas las carteleras del campus comparten horario
     * de semana y sólo difieren el fin de semana.
     */
    #[Route('/{dia}/copiar-a-habiles', name: 'spui_cms_energia_copiar', methods: ['POST'], requirements: ['dia' => '[1-7]'])]
    public function copiarAHabiles(int $id, int $dia, Request $request): Response
    {
        $pantalla = $this->getPantallaOr404($id);
        $origen   = $this->repo->findOneBy(['pantalla' => $pantalla, 'diaSemana' => $dia]);

        $grillaUrl = $this->generateUrl('spui_cms_energia_grilla', ['id' => $id]);

        if (!$origen) {
            $msg = 'Configurá primero el horario de ese día.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => $msg, 'type' => 'warning'], 404);
            }
            $this->addFlash('warning', $msg);
            return $this->redirect($grillaUrl);
        }

        $existentes = $this->repo->findByPantallaIndexadoPorDia($pantalla);
        $copiados   = 0;

        foreach ([1, 2, 3, 4, 5] as $destinoDia) {
            if ($destinoDia === $dia) {
                continue;
            }

            $destino = $existentes[$destinoDia] ?? null;
            if ($destino === null) {
                $destino = new ProgramacionEnergetica();
                $destino->setPantalla($pantalla);
                $destino->setDiaSemana($destinoDia);
                $this->em()->persist($destino);
            }

            $destino->setHoraEncendido($origen->getHoraEncendido());
            $destino->setHoraApagado($origen->getHoraApagado());
            $destino->setNivelBrillo($origen->getNivelBrillo());
            $copiados++;
        }

        $this->em()->flush();

        $msg = 'Horario de ' . self::DIAS[$dia] . ' copiado a ' . $copiados . ' día(s) hábil(es).';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg, 'redirect' => $grillaUrl]);
        }
        $this->addFlash('success', $msg);
        return $this->redirect($grillaUrl);
    }
}
