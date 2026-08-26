<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Edificio;
use SPUI\Form\EdificioType;
use SPUI\Repository\EdificioRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/spui/edificios')]
class EdificioCmsController extends AbstractController
{
    use CsrfProtegidoTrait;

    public function __construct(
        private readonly EdificioRepository $repo,
        private readonly ManagerRegistry $doctrine,
    ) {}

    private function em()
    {
        return $this->doctrine->getManager('SPUI');
    }

    #[Route('', name: 'spui_cms_edificios_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@SPUI/edificios/index.html.twig', [
            'edificios' => $this->repo->findBy([], ['nombre' => 'ASC']),
        ]);
    }

    #[Route('/{id}/ver', name: 'spui_cms_edificios_ver', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function ver(int $id, Request $request): Response
    {
        $edificio = $this->repo->find($id);
        if (!$edificio) { throw $this->createNotFoundException(); }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => $edificio->getNombre(),
                'html'  => $this->renderView('@SPUI/edificios/_view.html.twig', ['edificio' => $edificio]),
            ]);
        }
        return $this->redirectToRoute('spui_cms_edificios_index');
    }

    #[Route('/nuevo', name: 'spui_cms_edificios_nuevo', methods: ['GET', 'POST'])]
    public function nuevo(Request $request): Response
    {
        $edificio = new Edificio();
        $form = $this->createForm(EdificioType::class, $edificio, [
            'action' => $this->generateUrl('spui_cms_edificios_nuevo'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em()->persist($edificio);
            $this->em()->flush();
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'message' => 'Edificio "' . $edificio->getNombre() . '" creado correctamente.']);
            }
            $this->addFlash('success', 'Edificio "' . $edificio->getNombre() . '" creado correctamente.');
            return $this->redirectToRoute('spui_cms_edificios_index');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Nuevo edificio',
                'html'  => $this->renderView('@SPUI/edificios/_form.html.twig', ['form' => $form]),
            ]);
        }

        // El alta vive en el modal ABM; sin JS no hay pantalla propia.
        return $this->redirectToRoute('spui_cms_edificios_index');
    }

    #[Route('/{id}/editar', name: 'spui_cms_edificios_editar', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function editar(int $id, Request $request): Response
    {
        $edificio = $this->repo->find($id);
        if (!$edificio) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(EdificioType::class, $edificio, [
            'action' => $this->generateUrl('spui_cms_edificios_editar', ['id' => $id]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em()->flush();
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'message' => 'Edificio "' . $edificio->getNombre() . '" actualizado.']);
            }
            $this->addFlash('success', 'Edificio "' . $edificio->getNombre() . '" actualizado.');
            return $this->redirectToRoute('spui_cms_edificios_index');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Editar: ' . $edificio->getNombre(),
                'html'  => $this->renderView('@SPUI/edificios/_form.html.twig', ['form' => $form, 'edificio' => $edificio]),
            ]);
        }

        return $this->redirectToRoute('spui_cms_edificios_index');
    }

    #[Route('/{id}/toggle', name: 'spui_cms_edificios_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(int $id, Request $request): Response
    {
        $edificio = $this->repo->find($id);
        if (!$edificio) {
            throw $this->createNotFoundException();
        }
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        $edificio->setActivo(!$edificio->isActivo());
        $this->em()->flush();
        $msg = 'Edificio ' . ($edificio->isActivo() ? 'activado' : 'desactivado') . '.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_edificios_index');
    }

    #[Route('/{id}/eliminar', name: 'spui_cms_edificios_eliminar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function eliminar(int $id, Request $request): Response
    {
        $edificio = $this->repo->find($id);
        if (!$edificio) {
            throw $this->createNotFoundException();
        }
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        // Igual que en ubicaciones: faltaba comprobar las programaciones, y sin
        // eso el DELETE moría con un error SQL de integridad referencial.
        $bloqueos = [];

        if (!$edificio->getUbicaciones()->isEmpty()) {
            $bloqueos[] = 'tiene ' . $edificio->getUbicaciones()->count() . ' ubicación/es asociada/s: eliminalas primero';
        }

        if (!$edificio->getProgramaciones()->isEmpty()) {
            $bloqueos[] = 'lo usan ' . $edificio->getProgramaciones()->count() . ' regla(s) de programación: eliminá o reasigná esas reglas';
        }

        if ($bloqueos !== []) {
            $msg = 'No se puede eliminar "' . $edificio->getNombre() . '" porque ' . implode('; y ', $bloqueos) . '.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => $msg], 422);
            }
            $this->addFlash('error', $msg);
            return $this->redirectToRoute('spui_cms_edificios_index');
        }

        $nombre = $edificio->getNombre();
        $this->em()->remove($edificio);
        $this->em()->flush();
        $msg = 'Edificio "' . $nombre . '" eliminado.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_edificios_index');
    }
}
