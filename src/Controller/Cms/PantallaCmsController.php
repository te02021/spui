<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Pantalla;
use SPUI\Enum\EstadoPantalla;
use SPUI\Form\PantallaType;
use SPUI\Repository\PantallaRepository;
use SPUI\Service\AlcanceReproductorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/spui/pantallas')]
class PantallaCmsController extends AbstractController
{
    use BloqueoOfflineTrait;

    public function __construct(
        private readonly PantallaRepository $repo,
        private readonly AlcanceReproductorService $alcance,
        private readonly ManagerRegistry $doctrine,
    ) {}

    private function em()
    {
        return $this->doctrine->getManager('SPUI');
    }

    #[Route('', name: 'spui_cms_pantallas_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@SPUI/pantallas/index.html.twig', [
            'pantallas'   => $this->repo->findBy([], ['nombre' => 'ASC']),
            // Una sola consulta por request: resolverlo por fila dispararía la
            // cadena de relaciones por cada pantalla.
            'ids_offline' => $this->alcance->idsOfflineDeUnaVez(),
        ]);
    }

    /** Detalle de la pantalla (modal "Ver"). */
    #[Route('/{id}/ver', name: 'spui_cms_pantallas_ver', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function ver(int $id, Request $request): Response
    {
        $pantalla = $this->repo->find($id);
        if (!$pantalla) {
            throw $this->createNotFoundException();
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => $pantalla->getNombre(),
                'html'  => $this->renderView('@SPUI/pantallas/_view.html.twig', ['pantalla' => $pantalla]),
            ]);
        }

        return $this->redirectToRoute('spui_cms_pantallas_index');
    }

    #[Route('/nueva', name: 'spui_cms_pantallas_nueva', methods: ['GET', 'POST'])]
    public function nueva(Request $request): Response
    {
        $pantalla = new Pantalla();
        $form = $this->createForm(PantallaType::class, $pantalla, [
            'action' => $this->generateUrl('spui_cms_pantallas_nueva'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // El formulario ya asignó el reproductor, así que se puede saber a
            // qué equipo llegaría esta pantalla aunque todavía no exista.
            if ($r = $this->bloquearSiOffline($this->alcance->dePantalla($pantalla), $request, 'spui_cms_pantallas_index')) {
                return $r;
            }

            $this->em()->persist($pantalla);
            $this->em()->flush();
            $msg = 'Pantalla "' . $pantalla->getNombre() . '" creada correctamente.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'message' => $msg]);
            }
            $this->addFlash('success', $msg);
            return $this->redirectToRoute('spui_cms_pantallas_index');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Nueva pantalla',
                'html'  => $this->renderView('@SPUI/pantallas/_form.html.twig', ['form' => $form]),
            ]);
        }

        return $this->render('@SPUI/pantallas/nueva.html.twig', ['form' => $form]);
    }

    #[Route('/{id}/editar', name: 'spui_cms_pantallas_editar', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function editar(int $id, Request $request): Response
    {
        $pantalla = $this->repo->find($id);
        if (!$pantalla) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(PantallaType::class, $pantalla, [
            'action' => $this->generateUrl('spui_cms_pantallas_editar', ['id' => $id]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($r = $this->bloquearSiOffline($this->alcance->dePantalla($pantalla), $request, 'spui_cms_pantallas_index')) {
                return $r;
            }

            $this->em()->flush();
            $msg = 'Pantalla "' . $pantalla->getNombre() . '" actualizada.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'message' => $msg]);
            }
            $this->addFlash('success', $msg);
            return $this->redirectToRoute('spui_cms_pantallas_index');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Editar: ' . $pantalla->getNombre(),
                'html'  => $this->renderView('@SPUI/pantallas/_form.html.twig', ['form' => $form, 'pantalla' => $pantalla]),
            ]);
        }

        return $this->render('@SPUI/pantallas/editar.html.twig', [
            'form'     => $form,
            'pantalla' => $pantalla,
        ]);
    }

    #[Route('/{id}/toggle-estado', name: 'spui_cms_pantallas_toggle_estado', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleEstado(int $id, Request $request): Response
    {
        $pantalla = $this->repo->find($id);
        if (!$pantalla) {
            throw $this->createNotFoundException();
        }

        if ($r = $this->bloquearSiOffline($this->alcance->dePantalla($pantalla), $request, 'spui_cms_pantallas_index')) {
            return $r;
        }

        $nuevo = match ($pantalla->getEstado()) {
            EstadoPantalla::Activo        => EstadoPantalla::Inactivo,
            EstadoPantalla::Inactivo      => EstadoPantalla::Activo,
            EstadoPantalla::Mantenimiento => EstadoPantalla::Activo,
        };

        $pantalla->setEstado($nuevo);
        $this->em()->flush();
        $msg = 'Pantalla "' . $pantalla->getNombre() . '" → ' . $nuevo->value . '.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_pantallas_index');
    }

    #[Route('/{id}/eliminar', name: 'spui_cms_pantallas_eliminar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function eliminar(int $id, Request $request): Response
    {
        $pantalla = $this->repo->find($id);
        if (!$pantalla) {
            throw $this->createNotFoundException();
        }

        if ($pantalla->getReproductor() !== null) {
            $msg = 'No se puede eliminar: tiene un reproductor asociado ("' . $pantalla->getReproductor()->getHostname() . '"). Desasignálo primero desde la edición de la pantalla.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => $msg], 422);
            }
            $this->addFlash('error', $msg);
            return $this->redirectToRoute('spui_cms_pantallas_index');
        }

        $nombre = $pantalla->getNombre();
        $this->em()->remove($pantalla);
        $this->em()->flush();
        $msg = 'Pantalla "' . $nombre . '" eliminada.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_pantallas_index');
    }
}
