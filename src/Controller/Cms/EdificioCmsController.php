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

        return $this->render('@SPUI/edificios/nuevo.html.twig', ['form' => $form]);
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

        return $this->render('@SPUI/edificios/editar.html.twig', [
            'form'     => $form,
            'edificio' => $edificio,
        ]);
    }

    #[Route('/{id}/toggle', name: 'spui_cms_edificios_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(int $id, Request $request): Response
    {
        $edificio = $this->repo->find($id);
        if (!$edificio) {
            throw $this->createNotFoundException();
        }

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

        if (!$edificio->getUbicaciones()->isEmpty()) {
            $msg = 'No se puede eliminar: tiene ' . $edificio->getUbicaciones()->count() . ' ubicación/es asociada/s. Eliminá las ubicaciones primero.';
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
