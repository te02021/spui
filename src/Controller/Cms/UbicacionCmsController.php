<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Ubicacion;
use SPUI\Form\UbicacionType;
use SPUI\Repository\UbicacionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/spui/ubicaciones')]
class UbicacionCmsController extends AbstractController
{
    use CsrfProtegidoTrait;

    public function __construct(
        private readonly UbicacionRepository $repo,
        private readonly ManagerRegistry $doctrine,
    ) {}

    private function em()
    {
        return $this->doctrine->getManager('SPUI');
    }

    #[Route('', name: 'spui_cms_ubicaciones_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@SPUI/ubicaciones/index.html.twig', [
            'ubicaciones' => $this->repo->findBy([], ['id' => 'ASC']),
        ]);
    }

    #[Route('/{id}/ver', name: 'spui_cms_ubicaciones_ver', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function ver(int $id, Request $request): Response
    {
        $ubicacion = $this->repo->find($id);
        if (!$ubicacion) { throw $this->createNotFoundException(); }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => $ubicacion->getEdificio()->getNombre() . ($ubicacion->getSector() ? ' — ' . $ubicacion->getSector() : ''),
                'html'  => $this->renderView('@SPUI/ubicaciones/_view.html.twig', ['ubicacion' => $ubicacion]),
            ]);
        }
        return $this->redirectToRoute('spui_cms_ubicaciones_index');
    }

    #[Route('/nueva', name: 'spui_cms_ubicaciones_nueva', methods: ['GET', 'POST'])]
    public function nueva(Request $request): Response
    {
        $ubicacion = new Ubicacion();
        $form = $this->createForm(UbicacionType::class, $ubicacion, [
            'action' => $this->generateUrl('spui_cms_ubicaciones_nueva'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em()->persist($ubicacion);
            $this->em()->flush();
            $msg = 'Ubicación "' . $ubicacion->getEdificio()->getNombre() . '" creada correctamente.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'message' => $msg]);
            }
            $this->addFlash('success', $msg);
            return $this->redirectToRoute('spui_cms_ubicaciones_index');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Nueva ubicación',
                'html'  => $this->renderView('@SPUI/ubicaciones/_form.html.twig', ['form' => $form]),
            ]);
        }

        return $this->redirectToRoute('spui_cms_ubicaciones_index');
    }

    #[Route('/{id}/editar', name: 'spui_cms_ubicaciones_editar', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function editar(int $id, Request $request): Response
    {
        $ubicacion = $this->repo->find($id);
        if (!$ubicacion) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(UbicacionType::class, $ubicacion, [
            'action' => $this->generateUrl('spui_cms_ubicaciones_editar', ['id' => $id]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em()->flush();
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'message' => 'Ubicación actualizada correctamente.']);
            }
            $this->addFlash('success', 'Ubicación actualizada correctamente.');
            return $this->redirectToRoute('spui_cms_ubicaciones_index');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Editar: ' . $ubicacion->getEdificio()->getNombre() . ($ubicacion->getSector() ? ' — ' . $ubicacion->getSector() : ''),
                'html'  => $this->renderView('@SPUI/ubicaciones/_form.html.twig', ['form' => $form, 'ubicacion' => $ubicacion]),
            ]);
        }

        return $this->redirectToRoute('spui_cms_ubicaciones_index');
    }

    #[Route('/{id}/toggle', name: 'spui_cms_ubicaciones_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(int $id, Request $request): Response
    {
        $ubicacion = $this->repo->find($id);
        if (!$ubicacion) {
            throw $this->createNotFoundException();
        }
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        $ubicacion->setActivo(!$ubicacion->isActivo());
        $this->em()->flush();
        $msg = 'Ubicación ' . ($ubicacion->isActivo() ? 'activada' : 'desactivada') . '.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_ubicaciones_index');
    }

    #[Route('/{id}/eliminar', name: 'spui_cms_ubicaciones_eliminar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function eliminar(int $id, Request $request): Response
    {
        $ubicacion = $this->repo->find($id);
        if (!$ubicacion) {
            throw $this->createNotFoundException();
        }
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        // Dos referencias impiden borrar una ubicación. La de programaciones
        // faltaba, y sin ella el DELETE terminaba en un error SQL crudo de
        // integridad referencial.
        $bloqueos = [];

        if (!$ubicacion->getPantallas()->isEmpty()) {
            $bloqueos[] = 'tiene ' . $ubicacion->getPantallas()->count() . ' pantalla(s) asociada(s): eliminalas primero';
        }

        if (!$ubicacion->getProgramaciones()->isEmpty()) {
            $bloqueos[] = 'la usan ' . $ubicacion->getProgramaciones()->count() . ' regla(s) de programación: eliminá o reasigná esas reglas';
        }

        if ($bloqueos !== []) {
            $msg = 'No se puede eliminar "' . $ubicacion . '" porque ' . implode('; y ', $bloqueos) . '.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => $msg], 422);
            }
            $this->addFlash('error', $msg);
            return $this->redirectToRoute('spui_cms_ubicaciones_index');
        }

        $nombre = $ubicacion->getEdificio()->getNombre() . ($ubicacion->getSector() ? ' — ' . $ubicacion->getSector() : '');
        $this->em()->remove($ubicacion);
        $this->em()->flush();
        $msg = 'Ubicación "' . $nombre . '" eliminada.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_ubicaciones_index');
    }
}
