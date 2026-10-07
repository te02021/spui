<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\CronogramaItem;
use SPUI\Enum\TipoContenido;
use SPUI\Form\CronogramaItemType;
use SPUI\Repository\ContenidoRepository;
use SPUI\Repository\CronogramaItemRepository;
use SPUI\Service\AlcanceReproductorService;
use SPUI\Service\ComandoPublisherService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/spui/contenidos/{id}/cronograma', requirements: ['id' => '\d+'])]
class CronogramaCmsController extends AbstractController
{
    use BloqueoOfflineTrait;
    use CsrfProtegidoTrait;

    /** Lo dicen el servidor (alta y edición) y validacion-vivo.js mientras se escribe. */
    public const MSG_FIN_POSTERIOR = 'La hora de fin debe ser posterior a la hora de inicio.';

    public function __construct(
        private readonly ContenidoRepository $contenidoRepo,
        private readonly CronogramaItemRepository $itemRepo,
        private readonly ManagerRegistry $doctrine,
        private readonly AlcanceReproductorService $alcance,
        private readonly ComandoPublisherService $comandoPublisher,
    ) {}

    private function em()
    {
        return $this->doctrine->getManager('SPUI');
    }

    private function getContenidoOr404(int $id)
    {
        $c = $this->contenidoRepo->find($id);
        if (!$c || $c->getTipo() !== TipoContenido::Cronograma) {
            throw $this->createNotFoundException('Cronograma no encontrado.');
        }
        return $c;
    }

    /**
     * Listado de ítems del cronograma, en un modal (nivel 1).
     *
     * Antes era una página propia ("editor de cronograma"); ahora es un modal
     * como el resto del CMS, igual que la grilla de energía: agregar, editar y
     * eliminar se abren encima (nivel 2, como sub-vista), cada ítem se guarda
     * al guardarlo, y al terminar se cierra esa capa y este listado se
     * refresca solo (modal.js, closeModal2YRefrescaNivel1).
     */
    #[Route('', name: 'spui_cms_cronograma_items', methods: ['GET'])]
    public function items(int $id, Request $request): Response
    {
        $contenido = $this->getContenidoOr404($id);

        if (!$request->isXmlHttpRequest()) {
            // Sin XHR no hay modal donde pintarlo: se vuelve al listado de
            // contenidos, que es desde donde se abre.
            return $this->redirectToRoute('spui_cms_contenidos_index');
        }

        return $this->json([
            'title' => 'Ítems de «' . $contenido->getTitulo() . '»',
            'html'  => $this->renderView('@SPUI/cronograma/_items.html.twig', [
                'contenido'   => $contenido,
                'items'       => $this->itemRepo->findAllByContenidoOrdenado($contenido),
                'ids_offline' => $this->alcance->idsOfflineDeUnaVez(),
            ]),
        ]);
    }

    #[Route('/items/agregar', name: 'spui_cms_cronograma_item_agregar', methods: ['GET', 'POST'])]
    public function agregarItem(int $id, Request $request): Response
    {
        $contenido  = $this->getContenidoOr404($id);
        $actionUrl  = $this->generateUrl('spui_cms_cronograma_item_agregar', ['id' => $id]);
        $volverUrl  = $this->generateUrl('spui_cms_contenidos_index');

        if ($request->isMethod('GET')) {
            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'title' => 'Agregar ítem al cronograma',
                    'html'  => $this->renderView('@SPUI/cronograma/_item_agregar_form.html.twig', ['action_url' => $actionUrl]),
                ]);
            }
            return $this->redirect($volverUrl);
        }

        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        $nombre     = trim($request->request->get('nombre', ''));
        $aula       = trim($request->request->get('aula', '')) ?: null;
        $horaInicio = $request->request->get('hora_inicio', '');
        $horaFin    = $request->request->get('hora_fin', '');
        $dias       = $request->request->all('dias') ?? [];
        $activo     = (bool) $request->request->get('activo', false);

        // Por campo y no en una lista: cada error se muestra debajo de su input.
        $errors = [];
        if (!$nombre) { $errors['nombre'] = 'El nombre es requerido.'; }
        if (!$horaInicio) { $errors['hora_inicio'] = 'La hora de inicio es requerida.'; }
        if (!$horaFin) { $errors['hora_fin'] = 'La hora de fin es requerida.'; }
        if ($horaInicio && $horaFin && $horaFin <= $horaInicio) {
            $errors['hora_fin'] = self::MSG_FIN_POSTERIOR;
        }

        if ($errors) {
            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'success' => false,
                    'html'    => $this->renderView('@SPUI/cronograma/_item_agregar_form.html.twig', [
                        'action_url' => $actionUrl,
                        'errors'     => $errors,
                        // 'activo' va explícito (no omitido) para que al reintentar
                        // tras un error el switch conserve lo que eligió el usuario:
                        // el template hace v.activo ?? true, y ?? sólo captura null.
                        'values'     => ['nombre' => $nombre, 'aula' => $aula, 'hora_inicio' => $horaInicio, 'hora_fin' => $horaFin, 'dias' => $dias, 'activo' => $activo],
                    ]),
                ]);
            }
            $this->addFlash('error', implode(' ', $errors));
            return $this->redirect($volverUrl);
        }

        $reproductores = $this->alcance->deContenido($contenido);
        if ($r = $this->bloquearSiOffline($reproductores, $request, 'spui_cms_contenidos_index')) {
            return $r;
        }

        $mask = 0;
        foreach ($dias as $bit) { $mask |= (1 << (int) $bit); }
        if ($mask === 0) { $mask = 127; }

        $item = new CronogramaItem();
        $item->setContenido($contenido);
        $item->setNombre($nombre);
        $item->setAula($aula);
        $item->setHoraInicio(new DateTimeImmutable($horaInicio));
        $item->setHoraFin(new DateTimeImmutable($horaFin));
        $item->setDiasSemana($mask);
        $item->setActivo($activo);
        $item->setOrden(count($this->itemRepo->findBy(['contenido' => $contenido])) + 1);

        $this->em()->persist($item);
        $this->em()->flush();
        $this->comandoPublisher->pedirSyncAhora($reproductores, 'cronograma');

        // Sin redirect: en el modal, éxito = cerrar esta capa y refrescar el
        // listado de ítems de abajo.
        $msg = '"' . $nombre . '" agregado al cronograma.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
        return $this->redirect($volverUrl);
    }

    #[Route('/items/{itemId}/editar', name: 'spui_cms_cronograma_item_editar', methods: ['GET', 'POST'], requirements: ['itemId' => '\d+'])]
    public function editarItem(int $id, int $itemId, Request $request): Response
    {
        $contenido = $this->getContenidoOr404($id);
        $item      = $this->itemRepo->find($itemId);

        if (!$item || $item->getContenido()->getId() !== $id) {
            throw $this->createNotFoundException();
        }

        // Extraer días activos del bitmask para pre-poblar el form
        $diasDefault = [];
        for ($bit = 0; $bit < 7; $bit++) {
            if ($item->getDiasSemana() & (1 << $bit)) {
                $diasDefault[] = $bit;
            }
        }

        $form = $this->createForm(CronogramaItemType::class, $item, [
            'action'              => $this->generateUrl('spui_cms_cronograma_item_editar', ['id' => $id, 'itemId' => $itemId]),
            'dias_semana_default' => $diasDefault,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($item->getHoraFin() <= $item->getHoraInicio()) {
                $form->get('horaFin')->addError(new \Symfony\Component\Form\FormError(self::MSG_FIN_POSTERIOR));
                if ($request->isXmlHttpRequest()) {
                    return $this->json(['success' => false, 'html' => $this->renderView('@SPUI/cronograma/_item_form.html.twig', ['form' => $form])]);
                }
                return $this->redirectToRoute('spui_cms_contenidos_index');
            }

            $reproductores = $this->alcance->deContenido($contenido);
            if ($r = $this->bloquearSiOffline($reproductores, $request, 'spui_cms_contenidos_index')) {
                return $r;
            }

            $bits = $form->get('diasSemana')->getData() ?? [];
            $mask = 0;
            foreach ($bits as $bit) {
                $mask |= (1 << (int) $bit);
            }
            $item->setDiasSemana($mask ?: 127);

            $this->em()->flush();
            $this->comandoPublisher->pedirSyncAhora($reproductores, 'cronograma');

            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'success' => true,
                    'message' => '"' . $item->getNombre() . '" actualizado.',
                ]);
            }
            $this->addFlash('success', '"' . $item->getNombre() . '" actualizado.');
            return $this->redirectToRoute('spui_cms_contenidos_index');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Editar: ' . $item->getNombre(),
                'html'  => $this->renderView('@SPUI/cronograma/_item_form.html.twig', ['form' => $form]),
            ]);
        }

        return $this->redirectToRoute('spui_cms_contenidos_index');
    }

    #[Route('/items/{itemId}/eliminar', name: 'spui_cms_cronograma_item_eliminar', methods: ['POST'], requirements: ['itemId' => '\d+'])]
    public function eliminarItem(int $id, int $itemId, Request $request): Response
    {
        $contenido = $this->getContenidoOr404($id);
        $item      = $this->itemRepo->find($itemId);

        if (!$item || $item->getContenido()->getId() !== $id) {
            throw $this->createNotFoundException();
        }
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        $reproductores = $this->alcance->deContenido($contenido);
        if ($r = $this->bloquearSiOffline($reproductores, $request, 'spui_cms_contenidos_index')) {
            return $r;
        }

        $nombre = $item->getNombre();
        $this->em()->remove($item);
        $this->em()->flush();
        $this->comandoPublisher->pedirSyncAhora($reproductores, 'cronograma');

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'success' => true,
                'message' => '"' . $nombre . '" eliminado.',
            ]);
        }
        $this->addFlash('success', '"' . $nombre . '" eliminado.');
        return $this->redirectToRoute('spui_cms_contenidos_index');
    }

}
