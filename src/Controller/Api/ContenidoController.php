<?php

declare(strict_types=1);

namespace SPUI\Controller\Api;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Contenido;
use SPUI\Enum\EstadoContenido;
use SPUI\Enum\TipoContenido;
use SPUI\Repository\ContenidoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/spui/contenidos')]
class ContenidoController extends AbstractController
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ContenidoRepository $repo,
    ) {}

    private function em(): EntityManagerInterface
    {
        return $this->doctrine->getManager('SPUI');
    }

    private function serialize(Contenido $c): array
    {
        return [
            'id'               => $c->getId(),
            'titulo'           => $c->getTitulo(),
            'tipo'             => $c->getTipo()->value,
            'estado'           => $c->getEstado()->value,
            'duracion_seg'     => $c->getDuracionSegundos(),
            'ruta_archivo'     => $c->getRutaArchivo(),
            'contenido_texto'  => $c->getContenidoTexto(),
            'hash_archivo'     => $c->getHashArchivo(),
            'creado_por_id'    => $c->getCreadoPorId(),
            'creado_en'        => $c->getCreadoEn()->format('c'),
            'actualizado_en'   => $c->getActualizadoEn()->format('c'),
        ];
    }

    /** Los tipos que requieren contenido_texto en vez de archivo */
    private function esTipoTexto(TipoContenido $tipo): bool
    {
        return in_array($tipo, [TipoContenido::Texto, TipoContenido::Youtube, TipoContenido::Qr], true);
    }

    #[Route('', name: 'spui_contenidos_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $criteria = [];
        if ($request->query->has('tipo')) {
            $tipo = TipoContenido::tryFrom($request->query->getString('tipo'));
            if ($tipo === null) {
                return $this->json(['error' => 'Tipo inválido. Valores: texto, imagen, video, youtube, qr.'], Response::HTTP_BAD_REQUEST);
            }
            $criteria['tipo'] = $tipo;
        }
        if ($request->query->has('estado')) {
            $estado = EstadoContenido::tryFrom($request->query->getString('estado'));
            if ($estado === null) {
                return $this->json(['error' => 'Estado inválido. Valores: borrador, publicado, archivado.'], Response::HTTP_BAD_REQUEST);
            }
            $criteria['estado'] = $estado;
        }

        $items = $this->repo->findBy($criteria, ['titulo' => 'ASC']);

        return $this->json([
            'data'  => array_map($this->serialize(...), $items),
            'total' => count($items),
        ]);
    }

    #[Route('', name: 'spui_contenidos_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        $errors = [];
        if (empty($body['titulo']))      $errors[] = '"titulo" es requerido.';
        if (empty($body['tipo']))        $errors[] = '"tipo" es requerido.';
        if (empty($body['creado_por_id'])) $errors[] = '"creado_por_id" es requerido.';
        if (!empty($errors)) {
            return $this->json(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $tipo = TipoContenido::tryFrom($body['tipo']);
        if ($tipo === null) {
            return $this->json(['error' => 'Tipo inválido. Valores: texto, imagen, video, youtube, qr.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($this->esTipoTexto($tipo) && empty($body['contenido_texto'])) {
            return $this->json(['error' => 'Para tipo "'.$tipo->value.'" se requiere "contenido_texto".'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$this->esTipoTexto($tipo) && empty($body['ruta_archivo'])) {
            return $this->json(['error' => 'Para tipo "'.$tipo->value.'" se requiere "ruta_archivo".'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $estado = EstadoContenido::tryFrom($body['estado'] ?? 'borrador') ?? EstadoContenido::Borrador;

        $contenido = new Contenido();
        $contenido->setTitulo(trim($body['titulo']));
        $contenido->setTipo($tipo);
        $contenido->setEstado($estado);
        $contenido->setDuracionSegundos((int) ($body['duracion_seg'] ?? 10));
        $contenido->setCreadoPorId((int) $body['creado_por_id']);

        if ($this->esTipoTexto($tipo)) {
            $contenido->setContenidoTexto(trim($body['contenido_texto']));
        } else {
            $contenido->setRutaArchivo(trim($body['ruta_archivo']));
            if (!empty($body['hash_archivo'])) {
                $contenido->setHashArchivo(trim($body['hash_archivo']));
            }
        }

        $em = $this->em();
        $em->persist($contenido);
        $em->flush();

        return $this->json(['data' => $this->serialize($contenido)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'spui_contenidos_show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $contenido = $this->repo->find($id);
        if ($contenido === null) {
            return $this->json(['error' => 'Contenido no encontrado.'], Response::HTTP_NOT_FOUND);
        }
        return $this->json(['data' => $this->serialize($contenido)]);
    }

    #[Route('/{id}', name: 'spui_contenidos_update', methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $contenido = $this->repo->find($id);
        if ($contenido === null) {
            return $this->json(['error' => 'Contenido no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true) ?? [];

        if (isset($body['titulo'])) {
            $contenido->setTitulo(trim($body['titulo']));
        }
        if (isset($body['estado'])) {
            $estado = EstadoContenido::tryFrom($body['estado']);
            if ($estado === null) {
                return $this->json(['error' => 'Estado inválido. Valores: borrador, publicado, archivado.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $contenido->setEstado($estado);
        }
        if (isset($body['duracion_seg'])) {
            $contenido->setDuracionSegundos((int) $body['duracion_seg']);
        }
        if (array_key_exists('contenido_texto', $body)) {
            $contenido->setContenidoTexto($body['contenido_texto'] !== null ? trim($body['contenido_texto']) : null);
        }
        if (array_key_exists('ruta_archivo', $body)) {
            $contenido->setRutaArchivo($body['ruta_archivo'] !== null ? trim($body['ruta_archivo']) : null);
        }
        if (array_key_exists('hash_archivo', $body)) {
            $contenido->setHashArchivo($body['hash_archivo'] !== null ? trim($body['hash_archivo']) : null);
        }

        $this->em()->flush();

        return $this->json(['data' => $this->serialize($contenido)]);
    }

    #[Route('/{id}', name: 'spui_contenidos_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $contenido = $this->repo->find($id);
        if ($contenido === null) {
            return $this->json(['error' => 'Contenido no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        if (!$contenido->getPlaylistItems()->isEmpty()) {
            return $this->json(
                ['error' => 'El contenido está en uso en '.$contenido->getPlaylistItems()->count().' playlist(s). Retíralo primero.'],
                Response::HTTP_CONFLICT,
            );
        }

        $em = $this->em();
        $em->remove($contenido);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
