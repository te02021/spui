<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Reproductor;
use SPUI\Form\ReproductorType;
use SPUI\Repository\ReproductorRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/spui/reproductores')]
class ReproductorCmsController extends AbstractController
{
    public function __construct(
        private readonly ReproductorRepository $repo,
        private readonly ManagerRegistry $doctrine,
    ) {}

    private function em()
    {
        return $this->doctrine->getManager('SPUI');
    }

    #[Route('', name: 'spui_cms_reproductores_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@SPUI/reproductores/index.html.twig', [
            'reproductores' => $this->repo->findBy([], ['hostname' => 'ASC']),
        ]);
    }

    #[Route('/nuevo', name: 'spui_cms_reproductores_nuevo', methods: ['GET', 'POST'])]
    public function nuevo(Request $request): Response
    {
        $reproductor = new Reproductor();
        $form = $this->createForm(ReproductorType::class, $reproductor, [
            'action' => $this->generateUrl('spui_cms_reproductores_nuevo'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $rawKey = bin2hex(random_bytes(32));
            $reproductor->setApiKeyHash(hash('sha256', $rawKey));
            $this->em()->persist($reproductor);
            $this->em()->flush();

            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'success'  => true,
                    'apiKey'   => $rawKey,
                    'hostname' => $reproductor->getHostname(),
                    'message'  => 'Reproductor "' . $reproductor->getHostname() . '" registrado.',
                ]);
            }

            $request->getSession()->set('spui_reproductor_api_key', $rawKey);
            $request->getSession()->set('spui_reproductor_api_key_id', $reproductor->getId());
            return $this->redirectToRoute('spui_cms_reproductores_mostrar_clave', ['id' => $reproductor->getId()]);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Nuevo reproductor',
                'html'  => $this->renderView('@SPUI/reproductores/_form.html.twig', ['form' => $form]),
            ]);
        }

        return $this->render('@SPUI/reproductores/nuevo.html.twig', ['form' => $form]);
    }

    #[Route('/{id}/clave', name: 'spui_cms_reproductores_mostrar_clave', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function mostrarClave(int $id, Request $request): Response
    {
        $reproductor = $this->repo->find($id);
        $rawKey      = $request->getSession()->get('spui_reproductor_api_key');
        $keyId       = $request->getSession()->get('spui_reproductor_api_key_id');

        if (!$reproductor || !$rawKey || $keyId !== $reproductor->getId()) {
            return $this->redirectToRoute('spui_cms_reproductores_index');
        }

        $request->getSession()->remove('spui_reproductor_api_key');
        $request->getSession()->remove('spui_reproductor_api_key_id');

        return $this->render('@SPUI/reproductores/clave.html.twig', [
            'reproductor' => $reproductor,
            'apiKey'      => $rawKey,
        ]);
    }

    #[Route('/{id}/editar', name: 'spui_cms_reproductores_editar', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function editar(int $id, Request $request): Response
    {
        $reproductor = $this->repo->find($id);
        if (!$reproductor) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(ReproductorType::class, $reproductor, [
            'action' => $this->generateUrl('spui_cms_reproductores_editar', ['id' => $id]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em()->flush();
            $msg = 'Reproductor "' . $reproductor->getHostname() . '" actualizado.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'message' => $msg]);
            }
            $this->addFlash('success', $msg);
            return $this->redirectToRoute('spui_cms_reproductores_index');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Editar: ' . $reproductor->getHostname(),
                'html'  => $this->renderView('@SPUI/reproductores/_form.html.twig', ['form' => $form, 'reproductor' => $reproductor]),
            ]);
        }

        return $this->render('@SPUI/reproductores/editar.html.twig', [
            'form'         => $form,
            'reproductor'  => $reproductor,
        ]);
    }

    #[Route('/{id}/regenerar-clave', name: 'spui_cms_reproductores_regenerar_clave', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function regenerarClave(int $id, Request $request): Response
    {
        $reproductor = $this->repo->find($id);
        if (!$reproductor) {
            throw $this->createNotFoundException();
        }

        $rawKey = bin2hex(random_bytes(32));
        $reproductor->setApiKeyHash(hash('sha256', $rawKey));
        $this->em()->flush();

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'success'  => true,
                'apiKey'   => $rawKey,
                'hostname' => $reproductor->getHostname(),
                'message'  => 'Clave API regenerada para "' . $reproductor->getHostname() . '".',
            ]);
        }

        $request->getSession()->set('spui_reproductor_api_key', $rawKey);
        $request->getSession()->set('spui_reproductor_api_key_id', $reproductor->getId());
        return $this->redirectToRoute('spui_cms_reproductores_mostrar_clave', ['id' => $reproductor->getId()]);
    }

    #[Route('/{id}/eliminar', name: 'spui_cms_reproductores_eliminar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function eliminar(int $id, Request $request): Response
    {
        $reproductor = $this->repo->find($id);
        if (!$reproductor) {
            throw $this->createNotFoundException();
        }

        if (!$reproductor->getPantallas()->isEmpty()) {
            $msg = 'No se puede eliminar: tiene ' . $reproductor->getPantallas()->count() . ' pantalla(s) asignada(s). Desasignálas primero desde cada pantalla.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => $msg], 422);
            }
            $this->addFlash('error', $msg);
            return $this->redirectToRoute('spui_cms_reproductores_index');
        }

        $hostname = $reproductor->getHostname();
        $this->em()->remove($reproductor);
        $this->em()->flush();
        $msg = 'Reproductor "' . $hostname . '" eliminado.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_reproductores_index');
    }
}
