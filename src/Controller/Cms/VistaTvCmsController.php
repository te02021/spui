<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

use SPUI\Entity\Contenido;
use SPUI\Entity\Edificio;
use SPUI\Enum\OrigenCronograma;
use SPUI\Enum\TipoContenido;
use SPUI\Repository\ContenidoRepository;
use SPUI\Repository\EdificioRepository;
use SPUI\Service\PantallaTvService;
use SPUI\Service\PresentacionContenidoService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vista previa de cómo se ve un contenido en el TV (noviembre 2026).
 *
 * Dos entradas, el mismo dibujo (pantalla/_vista_previa.html.twig con
 * PantallaTvService): la de un contenido guardado (modal Ver y visor) y la
 * del formulario de alta o edición, con lo que se está eligiendo y todavía no
 * se guardó. Sólo dibujan: no guardan nada.
 */
#[Route('/spui/contenidos')]
class VistaTvCmsController extends AbstractController
{
    public function __construct(
        private readonly ContenidoRepository $contenidos,
        private readonly EdificioRepository $edificios,
        private readonly PantallaTvService $tv,
        private readonly PresentacionContenidoService $presentacion,
    ) {}

    #[Route('/{id}/pantalla', name: 'spui_cms_contenidos_pantalla', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function guardado(int $id, Request $request): Response
    {
        $c = $this->contenidos->find($id);
        if (!$c) {
            throw $this->createNotFoundException();
        }
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('spui_cms_contenidos_index');
        }

        return $this->respuesta($c, $request->query->getString('edificio'), null);
    }

    /**
     * Lo que se está cargando en el formulario: los campos llegan como los
     * manda el form (sin el archivo, que el navegador muestra por su cuenta).
     * En una edición se parte del contenido guardado, sin tocarlo.
     */
    #[Route('/vista-previa', name: 'spui_cms_contenidos_vista_previa', methods: ['POST'])]
    public function formulario(Request $request): Response
    {
        $p = $request->request;
        $guardado = $p->getString('vista_id') !== '' ? $this->contenidos->find((int) $p->getString('vista_id')) : null;

        if ($guardado) {
            // Una copia: lo que se prueba no puede terminar guardado.
            $c = clone $guardado;
            $c->setTitulo(trim($p->getString('titulo')) ?: $guardado->getTitulo());
        } else {
            $alta = $p->all('contenido');
            $tipo = TipoContenido::tryFrom((string) ($alta['tipo'] ?? ''));
            if (!$tipo) {
                return new JsonResponse(['html' => '']);
            }
            $c = (new Contenido())->setTipo($tipo)->setTitulo(trim((string) ($alta['titulo'] ?? '')));
        }

        if ($c->getTipo() === TipoContenido::Texto && $p->has('contenido_texto')) {
            $c->setContenidoTexto(trim($p->getString('contenido_texto')));
        }
        // Lo que no sea válido todavía (una fecha a medio escribir) se ignora:
        // la vista previa muestra lo que se pueda, el error lo da Guardar.
        $this->presentacion->aplicar($c, $request);

        // Un cronograma nuevo todavía no tiene ítems.
        $items = $guardado ? null : [];

        return $this->respuesta($c, $p->getString('vista_edificio'), $items, $guardado);
    }

    /** @param list<\SPUI\Entity\CronogramaItem>|null $items */
    private function respuesta(Contenido $c, string $edificioId, ?array $items, ?Contenido $conArchivo = null): JsonResponse
    {
        $conArchivo = $conArchivo ?? ($c->getId() ? $c : null);
        $porEdificio = $c->getTipo() === TipoContenido::Cronograma && $c->getCronogramaOrigen() === OrigenCronograma::Edificio;
        $edificio    = $porEdificio ? $this->edificioParaMirar($edificioId) : null;
        $ahora       = new \DateTimeImmutable();

        return new JsonResponse(['html' => $this->renderView('@SPUI/pantalla/_vista_previa.html.twig', [
            'tv'        => $this->tv->vista($c, $edificio, $items, $ahora),
            'media_url' => $conArchivo?->getRutaArchivo()
                ? $this->generateUrl('spui_cms_contenidos_archivo_preview', ['id' => $conArchivo->getId()])
                : null,
            'ahora'     => $ahora,
            'edificios' => $porEdificio ? $this->presentacion->edificios() : [],
            'edificio'  => $edificio,
            'titulo'    => $c->getTitulo(),
        ])]);
    }

    /**
     * Uno "del edificio de la pantalla" se ve distinto en cada edificio: se
     * mira en el elegido o, de entrada, en el primero que tenga un cronograma
     * vigente (así la vista previa muestra algo).
     */
    private function edificioParaMirar(string $id): ?Edificio
    {
        if ($id !== '' && ($e = $this->edificios->find((int) $id))) {
            return $e;
        }
        $hoy = new \DateTimeImmutable();
        foreach ($this->presentacion->edificios() as $e) {
            if ($this->contenidos->cronogramaVigenteDeEdificio($e, $hoy)) {
                return $e;
            }
        }
        return $this->presentacion->edificios()[0] ?? null;
    }
}
