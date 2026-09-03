<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Programacion;
use SPUI\Form\ProgramacionType;
use SPUI\Repository\ProgramacionRepository;
use SPUI\Service\AlcanceReproductorService;
use SPUI\Service\ComandoPublisherService;
use SPUI\Service\UsuarioResolverService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/spui/programacion')]
class ProgramacionCmsController extends AbstractController
{
    use BloqueoOfflineTrait;
    use CsrfProtegidoTrait;

    public function __construct(
        private readonly ProgramacionRepository $repo,
        private readonly AlcanceReproductorService $alcance,
        private readonly ComandoPublisherService $comandoPublisher,
        private readonly ManagerRegistry $doctrine,
        private readonly UsuarioResolverService $usuarios,
    ) {}

    private function em()
    {
        return $this->doctrine->getManager('SPUI');
    }

    #[Route('', name: 'spui_cms_programacion_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@SPUI/programacion/index.html.twig', [
            'programaciones' => $this->repo->findBy([], ['prioridad' => 'DESC', 'creadoEn' => 'DESC']),
            'dias'           => ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'],
            'ids_offline'    => $this->alcance->idsOfflineDeUnaVez(),
        ]);
    }

    /** Detalle de la regla (modal "Ver"). */
    #[Route('/{id}/ver', name: 'spui_cms_programacion_ver', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function ver(int $id, Request $request): Response
    {
        $prog = $this->repo->find($id);
        if (!$prog) { throw $this->createNotFoundException(); }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Regla de programación',
                'html'  => $this->renderView('@SPUI/programacion/_view.html.twig', [
                    'prog'             => $prog,
                    'creado_por_email' => $this->usuarios->email($prog->getCreadoPorId()),
                ]),
            ]);
        }

        return $this->redirectToRoute('spui_cms_programacion_index');
    }

    #[Route('/nueva-regla', name: 'spui_cms_programacion_nueva_form', methods: ['GET', 'POST'])]
    public function nueva(Request $request): Response
    {
        $prog = new Programacion();
        $form = $this->createForm(ProgramacionType::class, $prog, [
            'action' => $this->generateUrl('spui_cms_programacion_nueva_form'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $diasArray = $form->get('diasSemana')->getData() ?? [];
            $bitmask   = 0;
            foreach ($diasArray as $bit) { $bitmask |= (1 << (int) $bit); }
            $prog->setDiasSemana($bitmask ?: 127);
            $prog->setCreadoPorId((int) $this->getUser()->getId());

            // El objeto ya está poblado por el formulario, así que se puede
            // resolver a qué reproductores llegaría aunque todavía no exista.
            $reproductores = $this->alcance->deProgramacion($prog);
            if ($r = $this->bloquearSiOffline($reproductores, $request, 'spui_cms_programacion_index')) {
                return $r;
            }

            $this->em()->persist($prog);
            $this->em()->flush();
            $this->comandoPublisher->pedirSyncAhora($reproductores, 'programacion');

            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'message' => 'Programación creada correctamente.']);
            }
            $this->addFlash('success', 'Programación creada correctamente.');
            return $this->redirectToRoute('spui_cms_programacion_index');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Nueva regla de programación',
                'html'  => $this->renderView('@SPUI/programacion/_form.html.twig', ['form' => $form]),
            ]);
        }

        return $this->render('@SPUI/programacion/nueva.html.twig', ['form' => $form]);
    }

    #[Route('/{id}/editar', name: 'spui_cms_programacion_editar', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function editar(int $id, Request $request): Response
    {
        $prog = $this->repo->find($id);
        if (!$prog) { throw $this->createNotFoundException(); }

        $diasDefault = [];
        for ($bit = 0; $bit < 7; $bit++) {
            if ($prog->getDiasSemana() & (1 << $bit)) { $diasDefault[] = $bit; }
        }

        $form = $this->createForm(ProgramacionType::class, $prog, [
            'action'              => $this->generateUrl('spui_cms_programacion_editar', ['id' => $id]),
            'dias_semana_default' => $diasDefault,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $diasArray = $form->get('diasSemana')->getData() ?? [];
            $bitmask   = 0;
            foreach ($diasArray as $bit) { $bitmask |= (1 << (int) $bit); }
            $prog->setDiasSemana($bitmask ?: 127);

            $reproductores = $this->alcance->deProgramacion($prog);
            if ($r = $this->bloquearSiOffline($reproductores, $request, 'spui_cms_programacion_index')) {
                return $r;
            }

            $this->em()->flush();
            $this->comandoPublisher->pedirSyncAhora($reproductores, 'programacion');

            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'message' => 'Regla de programación actualizada.']);
            }
            $this->addFlash('success', 'Regla de programación actualizada.');
            return $this->redirectToRoute('spui_cms_programacion_index');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Editar regla de programación',
                'html'  => $this->renderView('@SPUI/programacion/_form.html.twig', [
                    'form'         => $form,
                    'submit_label' => 'Guardar cambios',
                ]),
            ]);
        }

        return $this->render('@SPUI/programacion/nueva.html.twig', ['form' => $form]);
    }

    #[Route('/{id}/toggle', name: 'spui_cms_programacion_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(int $id, Request $request): Response
    {
        $prog = $this->repo->find($id);
        if (!$prog) { throw $this->createNotFoundException(); }
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        $reproductores = $this->alcance->deProgramacion($prog);
        if ($r = $this->bloquearSiOffline($reproductores, $request, 'spui_cms_programacion_index')) {
            return $r;
        }

        $prog->setActivo(!$prog->isActivo());
        $this->em()->flush();
        $this->comandoPublisher->pedirSyncAhora($reproductores, 'programacion');
        $msg = 'Programación ' . ($prog->isActivo() ? 'activada' : 'desactivada') . '.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_programacion_index');
    }

    #[Route('/{id}/eliminar', name: 'spui_cms_programacion_eliminar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function eliminar(int $id, Request $request): Response
    {
        $prog = $this->repo->find($id);
        if (!$prog) { throw $this->createNotFoundException(); }
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        // Se resuelve ANTES de borrar: después la entidad ya no tiene sus
        // relaciones y no habría forma de saber a quién afectaba.
        $reproductores = $this->alcance->deProgramacion($prog);
        if ($r = $this->bloquearSiOffline($reproductores, $request, 'spui_cms_programacion_index')) {
            return $r;
        }

        $this->em()->remove($prog);
        $this->em()->flush();
        $this->comandoPublisher->pedirSyncAhora($reproductores, 'programacion');
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => 'Programación eliminada.']);
        }
        $this->addFlash('success', 'Programación eliminada.');
        return $this->redirectToRoute('spui_cms_programacion_index');
    }
}
