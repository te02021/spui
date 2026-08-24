<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\CodigoQr;
use SPUI\Entity\Contenido;
use SPUI\Form\CodigoQrType;
use SPUI\Repository\CodigoQrRepository;
use SPUI\Repository\ContenidoRepository;
use SPUI\Service\ContenidoQrService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * CU-10 — Gestión de códigos QR dinámicos y su estadística de escaneos.
 *
 * Un código vive aparte del contenido que lo muestra: se puede cambiar la URL
 * destino sin tocar la pantalla, porque lo que el QR codifica es el redirect
 * del CMS (/api/spui/qr/{id}/r), no el destino final.
 */
#[Route('/spui/qr')]
class CodigoQrCmsController extends AbstractController
{
    public function __construct(
        private readonly CodigoQrRepository $repo,
        private readonly ContenidoRepository $contenidoRepo,
        private readonly ContenidoQrService $qrService,
        private readonly ManagerRegistry $doctrine,
    ) {}

    private function em()
    {
        return $this->doctrine->getManager('SPUI');
    }

    /** Contenidos que muestran un código dado (para avisar antes de borrarlo). */
    private function contenidosQueUsan(CodigoQr $qr): array
    {
        return $this->contenidoRepo->findBy(['codigoQr' => $qr]);
    }

    #[Route('', name: 'spui_cms_qr_index', methods: ['GET'])]
    public function index(): Response
    {
        $codigos = $this->repo->findBy([], ['creadoEn' => 'DESC']);

        $usos = [];
        foreach ($codigos as $qr) {
            $usos[$qr->getId()] = count($this->contenidosQueUsan($qr));
        }

        return $this->render('@SPUI/qr/index.html.twig', [
            'codigos' => $codigos,
            'usos'    => $usos,
        ]);
    }

    #[Route('/{id}/ver', name: 'spui_cms_qr_ver', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function ver(int $id, Request $request): Response
    {
        $qr = $this->repo->find($id);
        if (!$qr) { throw $this->createNotFoundException(); }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => $qr->getEtiqueta(),
                'html'  => $this->renderView('@SPUI/qr/_view.html.twig', [
                    'qr'         => $qr,
                    'contenidos' => $this->contenidosQueUsan($qr),
                ]),
            ]);
        }
        return $this->redirectToRoute('spui_cms_qr_index');
    }

    #[Route('/nuevo', name: 'spui_cms_qr_nuevo', methods: ['GET', 'POST'])]
    public function nuevo(Request $request): Response
    {
        $qr   = new CodigoQr();
        $form = $this->createForm(CodigoQrType::class, $qr, [
            'action' => $this->generateUrl('spui_cms_qr_nuevo'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em()->persist($qr);
            $this->em()->flush();

            $msg = 'Código QR "' . $qr->getEtiqueta() . '" creado.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'message' => $msg]);
            }
            $this->addFlash('success', $msg);
            return $this->redirectToRoute('spui_cms_qr_index');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Nuevo código QR',
                'html'  => $this->renderView('@SPUI/qr/_form.html.twig', ['form' => $form]),
            ]);
        }
        return $this->redirectToRoute('spui_cms_qr_index');
    }

    #[Route('/{id}/editar', name: 'spui_cms_qr_editar', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function editar(int $id, Request $request): Response
    {
        $qr = $this->repo->find($id);
        if (!$qr) { throw $this->createNotFoundException(); }

        $form = $this->createForm(CodigoQrType::class, $qr, [
            'action' => $this->generateUrl('spui_cms_qr_editar', ['id' => $id]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em()->flush();

            // No hace falta regenerar los PNG: codifican el redirect, que no cambia.
            $msg = 'Código QR "' . $qr->getEtiqueta() . '" actualizado.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'message' => $msg]);
            }
            $this->addFlash('success', $msg);
            return $this->redirectToRoute('spui_cms_qr_index');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Editar: ' . $qr->getEtiqueta(),
                'html'  => $this->renderView('@SPUI/qr/_form.html.twig', ['form' => $form, 'qr' => $qr]),
            ]);
        }
        return $this->redirectToRoute('spui_cms_qr_index');
    }

    #[Route('/{id}/toggle', name: 'spui_cms_qr_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(int $id, Request $request): Response
    {
        $qr = $this->repo->find($id);
        if (!$qr) { throw $this->createNotFoundException(); }

        $qr->setActivo(!$qr->isActivo());
        $this->em()->flush();

        $msg = 'Código QR "' . $qr->getEtiqueta() . '" ' . ($qr->isActivo() ? 'activado.' : 'desactivado: deja de redirigir.');
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg, 'type' => $qr->isActivo() ? 'success' : 'warning']);
        }
        $this->addFlash($qr->isActivo() ? 'success' : 'warning', $msg);
        return $this->redirectToRoute('spui_cms_qr_index');
    }

    #[Route('/{id}/eliminar', name: 'spui_cms_qr_eliminar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function eliminar(int $id, Request $request): Response
    {
        $qr = $this->repo->find($id);
        if (!$qr) { throw $this->createNotFoundException(); }

        $enUso = $this->contenidosQueUsan($qr);
        if ($enUso !== []) {
            $msg = 'No se puede eliminar: lo usan ' . count($enUso) . ' contenido(s). '
                 . 'Eliminá esos contenidos primero, o desactivá el código para que deje de redirigir.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => $msg, 'type' => 'warning'], 409);
            }
            $this->addFlash('warning', $msg);
            return $this->redirectToRoute('spui_cms_qr_index');
        }

        $etiqueta = $qr->getEtiqueta();
        $this->em()->remove($qr);
        $this->em()->flush();

        $msg = 'Código QR "' . $etiqueta . '" eliminado.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_qr_index');
    }
}
