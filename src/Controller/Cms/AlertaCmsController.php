<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\AlertaEmergencia;
use SPUI\Form\AlertaType;
use SPUI\Repository\AlertaEmergenciaRepository;
use SPUI\Service\AlcanceReproductorService;
use SPUI\Service\AlertaPublisherService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/spui/alertas')]
class AlertaCmsController extends AbstractController
{
    use BloqueoOfflineTrait;

    public function __construct(
        private readonly AlertaEmergenciaRepository $repo,
        private readonly AlertaPublisherService $publisher,
        private readonly AlcanceReproductorService $alcance,
        private readonly ManagerRegistry $doctrine,
    ) {}

    private function em()
    {
        return $this->doctrine->getManager('SPUI');
    }

    #[Route('', name: 'spui_cms_alertas_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@SPUI/alertas/index.html.twig', [
            'alertas' => $this->repo->findBy([], ['activa' => 'DESC', 'prioridad' => 'DESC', 'creadaEn' => 'DESC']),
        ]);
    }

    #[Route('/nueva', name: 'spui_cms_alertas_nueva_form', methods: ['GET', 'POST'])]
    public function nueva(Request $request): Response
    {
        $alerta = new AlertaEmergencia();
        $alerta->setPrioridad(10);
        $form = $this->createForm(AlertaType::class, $alerta, [
            'action' => $this->generateUrl('spui_cms_alertas_nueva_form'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $alerta->setCreadoPorId((int) $this->getUser()->getId());
            $this->em()->persist($alerta);
            $this->em()->flush();
            $msg = 'Alerta "' . $alerta->getTitulo() . '" creada.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'message' => $msg]);
            }
            $this->addFlash('success', $msg);
            return $this->redirectToRoute('spui_cms_alertas_index');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Nueva alerta de emergencia',
                'html'  => $this->renderView('@SPUI/alertas/_form.html.twig', ['form' => $form]),
            ]);
        }

        return $this->redirectToRoute('spui_cms_alertas_index');
    }

    /** Detalle completo de la alerta (modal "Ver"). */
    #[Route('/{id}/ver', name: 'spui_cms_alertas_ver', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function ver(int $id, Request $request): Response
    {
        $alerta = $this->repo->find($id);
        if (!$alerta) {
            throw $this->createNotFoundException();
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Alerta: ' . $alerta->getTitulo(),
                'html'  => $this->renderView('@SPUI/alertas/_view.html.twig', ['alerta' => $alerta]),
            ]);
        }

        return $this->redirectToRoute('spui_cms_alertas_index');
    }

    /**
     * Editar una alerta. Sólo si está inactiva: cambiarle el texto mientras se
     * está mostrando en las pantallas dejaría al CMS y a los nodos desincronizados
     * (el push por MQTT ya salió con el contenido viejo).
     */
    #[Route('/{id}/editar', name: 'spui_cms_alertas_editar', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function editar(int $id, Request $request): Response
    {
        $alerta = $this->repo->find($id);
        if (!$alerta) {
            throw $this->createNotFoundException();
        }

        if ($alerta->isActiva()) {
            $msg = 'No se puede editar una alerta activa. Desactivala primero.';
            if ($request->isXmlHttpRequest()) {
                // En POST el JS espera {success:false}; en GET espera {title,html},
                // así que se responde 200 con el motivo dentro del modal.
                if ($request->isMethod('POST')) {
                    return $this->json(['success' => false, 'message' => $msg, 'type' => 'warning'], 422);
                }
                return $this->json([
                    'title' => 'Alerta activa',
                    'html'  => '<div class="alert alert-warning mb-0">' . $msg . '</div>'
                             . '<div class="d-flex justify-content-end pt-3 mt-3 border-top">'
                             . '<button type="button" class="unraf-btn spui-btn-back" data-spui-close>Entendido</button></div>',
                ]);
            }
            $this->addFlash('warning', $msg);
            return $this->redirectToRoute('spui_cms_alertas_index');
        }

        $form = $this->createForm(AlertaType::class, $alerta, [
            'action' => $this->generateUrl('spui_cms_alertas_editar', ['id' => $id]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em()->flush();
            $msg = 'Alerta "' . $alerta->getTitulo() . '" actualizada.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'message' => $msg]);
            }
            $this->addFlash('success', $msg);
            return $this->redirectToRoute('spui_cms_alertas_index');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Editar: ' . $alerta->getTitulo(),
                'html'  => $this->renderView('@SPUI/alertas/_form.html.twig', ['form' => $form, 'alerta' => $alerta, 'modo' => 'edicion']),
            ]);
        }

        return $this->redirectToRoute('spui_cms_alertas_index');
    }

    #[Route('/{id}/activar', name: 'spui_cms_alertas_activar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function activar(int $id, Request $request): Response
    {
        $alerta = $this->repo->find($id);
        if (!$alerta) { throw $this->createNotFoundException(); }

        if ($alerta->isActiva()) {
            $msg = 'La alerta ya está activa.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => $msg, 'type' => 'warning'], 422);
            }
            $this->addFlash('warning', $msg);
            return $this->redirectToRoute('spui_cms_alertas_index');
        }
        if ($alerta->haExpirado()) {
            $msg = 'La alerta expiró y no puede activarse.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => $msg], 422);
            }
            $this->addFlash('error', $msg);
            return $this->redirectToRoute('spui_cms_alertas_index');
        }

        if ($r = $this->bloquearSiOffline($this->alcance->deAlerta($alerta), $request, 'spui_cms_alertas_index')) {
            return $r;
        }

        $alerta->activar();
        $this->em()->flush();
        $this->publisher->publicarActivacion($alerta);
        $msg = 'Alerta "' . $alerta->getTitulo() . '" ACTIVADA. Reproductores notificados vía MQTT.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_alertas_index');
    }

    #[Route('/{id}/desactivar', name: 'spui_cms_alertas_desactivar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function desactivar(int $id, Request $request): Response
    {
        $alerta = $this->repo->find($id);
        if (!$alerta) { throw $this->createNotFoundException(); }

        if (!$alerta->isActiva()) {
            $msg = 'La alerta ya está inactiva.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => $msg, 'type' => 'warning'], 422);
            }
            $this->addFlash('warning', $msg);
            return $this->redirectToRoute('spui_cms_alertas_index');
        }

        if ($r = $this->bloquearSiOffline($this->alcance->deAlerta($alerta), $request, 'spui_cms_alertas_index')) {
            return $r;
        }

        $alerta->desactivar();
        $this->em()->flush();
        $this->publisher->publicarDesactivacion($alerta);
        $msg = 'Alerta "' . $alerta->getTitulo() . '" desactivada.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_alertas_index');
    }

    #[Route('/{id}/eliminar', name: 'spui_cms_alertas_eliminar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function eliminar(int $id, Request $request): Response
    {
        $alerta = $this->repo->find($id);
        if (!$alerta) { throw $this->createNotFoundException(); }

        if ($alerta->isActiva()) {
            $msg = 'No se puede eliminar una alerta activa. Desactivala primero.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => $msg], 422);
            }
            $this->addFlash('error', $msg);
            return $this->redirectToRoute('spui_cms_alertas_index');
        }

        $titulo = $alerta->getTitulo();
        $this->em()->remove($alerta);
        $this->em()->flush();
        $msg = 'Alerta "' . $titulo . '" eliminada.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_alertas_index');
    }
}
