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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/spui/contenidos/{id}/cronograma', requirements: ['id' => '\d+'])]
class CronogramaCmsController extends AbstractController
{
    use CsrfProtegidoTrait;

    public function __construct(
        private readonly ContenidoRepository $contenidoRepo,
        private readonly CronogramaItemRepository $itemRepo,
        private readonly ManagerRegistry $doctrine,
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

    #[Route('', name: 'spui_cms_cronograma_builder', methods: ['GET'])]
    public function builder(int $id): Response
    {
        $contenido = $this->getContenidoOr404($id);

        return $this->render('@SPUI/cronograma/builder.html.twig', [
            'contenido' => $contenido,
            'items'     => $this->itemRepo->findAllByContenidoOrdenado($contenido),
        ]);
    }

    #[Route('/items/agregar', name: 'spui_cms_cronograma_item_agregar', methods: ['GET', 'POST'])]
    public function agregarItem(int $id, Request $request): Response
    {
        $contenido  = $this->getContenidoOr404($id);
        $actionUrl  = $this->generateUrl('spui_cms_cronograma_item_agregar', ['id' => $id]);
        $builderUrl = $this->generateUrl('spui_cms_cronograma_builder', ['id' => $id]);

        if ($request->isMethod('GET')) {
            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'title' => 'Agregar ítem al cronograma',
                    'html'  => $this->renderView('@SPUI/cronograma/_item_agregar_form.html.twig', ['action_url' => $actionUrl]),
                ]);
            }
            return $this->redirect($builderUrl);
        }

        $nombre     = trim($request->request->get('nombre', ''));
        $aula       = trim($request->request->get('aula', '')) ?: null;
        $horaInicio = $request->request->get('hora_inicio', '');
        $horaFin    = $request->request->get('hora_fin', '');
        $dias       = $request->request->all('dias') ?? [];
        $activo     = (bool) $request->request->get('activo', false);

        $errors = [];
        if (!$nombre) { $errors[] = 'El nombre es requerido.'; }
        if (!$horaInicio) { $errors[] = 'La hora de inicio es requerida.'; }
        if (!$horaFin) { $errors[] = 'La hora de fin es requerida.'; }
        if ($horaInicio && $horaFin && $horaFin <= $horaInicio) {
            $errors[] = 'La hora de fin debe ser posterior a la hora de inicio.';
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
            return $this->redirect($builderUrl);
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

        $msg = '"' . $nombre . '" agregado al cronograma.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg, 'redirect' => $builderUrl]);
        }
        $this->addFlash('success', $msg);
        return $this->redirect($builderUrl);
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
                $form->get('horaFin')->addError(new \Symfony\Component\Form\FormError('La hora de fin debe ser posterior a la hora de inicio.'));
                if ($request->isXmlHttpRequest()) {
                    return $this->json(['success' => false, 'html' => $this->renderView('@SPUI/cronograma/_item_form.html.twig', ['form' => $form])]);
                }
                return $this->redirectToRoute('spui_cms_cronograma_builder', ['id' => $id]);
            }

            $bits = $form->get('diasSemana')->getData() ?? [];
            $mask = 0;
            foreach ($bits as $bit) {
                $mask |= (1 << (int) $bit);
            }
            $item->setDiasSemana($mask ?: 127);

            $this->em()->flush();

            $builderUrl = $this->generateUrl('spui_cms_cronograma_builder', ['id' => $id]);
            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'success'  => true,
                    'message'  => '"' . $item->getNombre() . '" actualizado.',
                    'redirect' => $builderUrl,
                ]);
            }
            $this->addFlash('success', '"' . $item->getNombre() . '" actualizado.');
            return $this->redirect($builderUrl);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Editar: ' . $item->getNombre(),
                'html'  => $this->renderView('@SPUI/cronograma/_item_form.html.twig', ['form' => $form]),
            ]);
        }

        return $this->redirectToRoute('spui_cms_cronograma_builder', ['id' => $id]);
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

        $nombre = $item->getNombre();
        $this->em()->remove($item);
        $this->em()->flush();

        $builderUrl = $this->generateUrl('spui_cms_cronograma_builder', ['id' => $id]);
        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'success'  => true,
                'message'  => '"' . $nombre . '" eliminado.',
                'redirect' => $builderUrl,
            ]);
        }
        $this->addFlash('success', '"' . $nombre . '" eliminado.');
        return $this->redirect($builderUrl);
    }

}
