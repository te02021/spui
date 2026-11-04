<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\CronogramaItem;
use SPUI\Enum\TipoContenido;
use SPUI\Enum\TipoItemCronograma;
use SPUI\Form\ProgramacionType;
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
        $contenido = $this->getContenidoOr404($id);
        $item      = (new CronogramaItem())->setContenido($contenido);
        $action    = $this->generateUrl('spui_cms_cronograma_item_agregar', ['id' => $id]);

        return $this->guardarItem($item, $request, $action, 'Agregar ítem al cronograma', 'spui-btn-crear', 'agregado al cronograma');
    }

    #[Route('/items/{itemId}/editar', name: 'spui_cms_cronograma_item_editar', methods: ['GET', 'POST'], requirements: ['itemId' => '\d+'])]
    public function editarItem(int $id, int $itemId, Request $request): Response
    {
        $this->getContenidoOr404($id);
        $item = $this->itemRepo->find($itemId);

        if (!$item || $item->getContenido()->getId() !== $id) {
            throw $this->createNotFoundException();
        }
        $action = $this->generateUrl('spui_cms_cronograma_item_editar', ['id' => $id, 'itemId' => $itemId]);

        return $this->guardarItem($item, $request, $action, 'Editar: ' . $item->getNombre(), 'spui-btn-editar', 'actualizado');
    }

    /**
     * Alta y edición de un ítem: el mismo formulario y las mismas reglas.
     * En el modal, éxito = cerrar esta capa y refrescar el listado de abajo.
     */
    private function guardarItem(CronogramaItem $item, Request $request, string $action, string $titulo, string $boton, string $hecho): Response
    {
        $volver = $this->redirectToRoute('spui_cms_contenidos_index');
        $vista  = fn(array $v, array $err = []) => $this->renderView('@SPUI/cronograma/_item_form.html.twig', [
            'action_url' => $action, 'v' => $v, 'err' => $err, 'boton' => $boton,
        ]);

        if ($request->isMethod('GET')) {
            return $request->isXmlHttpRequest()
                ? $this->json(['title' => $titulo, 'html' => $vista($this->valoresItem($item))])
                : $volver;
        }

        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        $v      = $this->valoresDelPedido($request);
        $errors = $this->validarItem($v);
        if ($errors) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'html' => $vista($v, $errors)]);
            }
            $this->addFlash('error', implode(' ', $errors));
            return $volver;
        }

        $reproductores = $this->alcance->deContenido($item->getContenido());
        if ($r = $this->bloquearSiOffline($reproductores, $request, 'spui_cms_contenidos_index')) {
            return $r;
        }

        $tipo = TipoItemCronograma::from($v['tipo_item']);
        $mask = 0;
        foreach ($v['dias'] as $bit) { $mask |= (1 << (int) $bit); }

        $item->setTipo($tipo)
            ->setNombre($v['nombre'])
            ->setAula($v['aula'] ?: null)
            ->setCarrera($v['carrera'] ?: null)
            ->setAnio($v['anio'] ?: null)
            ->setDocente($v['docente'] ?: null)
            ->setHoraInicio(new DateTimeImmutable($v['hora_inicio']))
            ->setHoraFin($v['hora_fin'] !== '' ? new DateTimeImmutable($v['hora_fin']) : null)
            // Un evento usa su fecha; una clase, sus días. El otro dato no se
            // borra del todo (los días quedan), pero no se usa.
            ->setFecha($tipo->tieneFecha() ? new DateTimeImmutable($v['fecha']) : null)
            ->setActivo($v['activo']);
        if (!$tipo->tieneFecha()) {
            $item->setDiasSemana($mask);
        }

        if ($item->getId() === null) {
            $item->setOrden(count($this->itemRepo->findBy(['contenido' => $item->getContenido()])) + 1);
            $this->em()->persist($item);
        }
        $this->em()->flush();
        $this->comandoPublisher->pedirSyncAhora($reproductores, 'cronograma');

        $msg = '"' . $item->getNombre() . '" ' . $hecho . '.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
        return $volver;
    }

    /** @return array<string, mixed> Lo guardado en el ítem, para el formulario. */
    private function valoresItem(CronogramaItem $item): array
    {
        $dias = [];
        for ($bit = 0; $bit < 7; $bit++) {
            if ($item->getDiasSemana() & (1 << $bit)) {
                $dias[] = $bit;
            }
        }

        return [
            'tipo_item'   => $item->getTipo()->value,
            'nombre'      => $item->getNombre(),
            'aula'        => $item->getAula(),
            'carrera'     => $item->getCarrera(),
            'anio'        => $item->getAnio(),
            'docente'     => $item->getDocente(),
            'hora_inicio' => $item->getHoraInicio()?->format('H:i'),
            'hora_fin'    => $item->getHoraFin()?->format('H:i'),
            // Un ítem nuevo arranca de lunes a viernes.
            'dias'        => $item->getId() ? $dias : [0, 1, 2, 3, 4],
            'fecha'       => $item->getFecha()?->format('Y-m-d'),
            'activo'      => $item->isActivo(),
        ];
    }

    /** @return array<string, mixed> Lo enviado, normalizado (también para volver a mostrarlo). */
    private function valoresDelPedido(Request $request): array
    {
        $p = $request->request;
        return [
            'tipo_item'   => TipoItemCronograma::tryFrom($p->getString('tipo_item'))?->value ?? TipoItemCronograma::Clase->value,
            'nombre'      => trim($p->getString('nombre')),
            'aula'        => trim($p->getString('aula')),
            'carrera'     => trim($p->getString('carrera')),
            'anio'        => trim($p->getString('anio')),
            'docente'     => trim($p->getString('docente')),
            'hora_inicio' => $p->getString('hora_inicio'),
            'hora_fin'    => $p->getString('hora_fin'),
            'dias'        => array_values(array_filter($p->all('dias'), fn($b) => ctype_digit((string) $b) && (int) $b <= 6)),
            'fecha'       => $p->getString('fecha'),
            // Explícito, no omitido: así al reintentar tras un error el switch
            // conserva lo que eligió el usuario (el template hace v.activo ?? true).
            'activo'      => $p->getBoolean('activo'),
        ];
    }

    /**
     * Por campo y no en una lista: cada error se muestra debajo de su input.
     *
     * @return array<string, string>
     */
    private function validarItem(array $v): array
    {
        $errors = [];
        $evento = TipoItemCronograma::from($v['tipo_item'])->tieneFecha();
        if ($v['nombre'] === '') { $errors['nombre'] = 'Indicá la materia o actividad.'; }
        if (!preg_match('/^\d{2}:\d{2}$/', $v['hora_inicio'])) { $errors['hora_inicio'] = 'La hora de inicio es requerida.'; }
        // Un evento puede no tener hora de fin: se muestra "Desde las 18:00".
        if ($v['hora_fin'] === '' && !$evento) {
            $errors['hora_fin'] = 'La hora de fin es requerida.';
        } elseif ($v['hora_fin'] !== '' && !preg_match('/^\d{2}:\d{2}$/', $v['hora_fin'])) {
            $errors['hora_fin'] = 'La hora no es válida.';
        }
        if (!isset($errors['hora_inicio']) && !isset($errors['hora_fin']) && $v['hora_fin'] !== '' && $v['hora_fin'] <= $v['hora_inicio']) {
            $errors['hora_fin'] = self::MSG_FIN_POSTERIOR;
        }

        if ($evento) {
            $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $v['fecha']);
            if (!$fecha || $fecha->format('Y-m-d') !== $v['fecha']) {
                $errors['fecha'] = 'Indicá la fecha del evento.';
            }
        } elseif (!$v['dias']) {
            $errors['dias'] = ProgramacionType::MSG_DIAS_REQUERIDOS;
        }

        foreach (['nombre' => 200, 'aula' => 100, 'carrera' => 60, 'anio' => 40, 'docente' => 255] as $campo => $max) {
            if (mb_strlen($v[$campo]) > $max) {
                $errors[$campo] = sprintf('Hasta %d caracteres.', $max);
            }
        }

        return $errors;
    }

    /**
     * Activar / desactivar un ítem desde el listado: el mismo cambio que el
     * switch del formulario. Un ítem inactivo no se manda a la pantalla.
     */
    #[Route('/items/{itemId}/toggle', name: 'spui_cms_cronograma_item_toggle', methods: ['POST'], requirements: ['itemId' => '\d+'])]
    public function toggleItem(int $id, int $itemId, Request $request): Response
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

        $item->setActivo(!$item->isActivo());
        $this->em()->flush();
        $this->comandoPublisher->pedirSyncAhora($reproductores, 'cronograma');

        $msg = '"' . $item->getNombre() . '" ' . ($item->isActivo() ? 'activado' : 'desactivado') . '.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
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
