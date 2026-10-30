<?php

declare(strict_types=1);

namespace SPUI\Service;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Paginación de los listados del CMS (30/10/2026), del lado del servidor:
 * la consulta trae sólo la página pedida, aunque haya miles de registros.
 *
 * La página viaja en la URL (?pagina=N): así el refresco automático
 * (auto-refresh.js pide location.pathname + location.search) y los avisos
 * MQTT siguen mostrando la misma página. Una página fuera de rango se lleva
 * a la última que existe, en vez de mostrar una lista vacía.
 *
 * La renderiza templates/_partials/paginacion.html.twig.
 */
final class Paginador
{
    public const POR_PAGINA = 10;

    /**
     * @template T of object
     * @param ObjectRepository<T>    $repo
     * @param array<string, mixed>   $criterio
     * @param array<string, string>  $orden
     * @return array{items: list<T>, pagina: int, paginas: int, total: int, desde: int, hasta: int}
     */
    public function paginar(ObjectRepository $repo, array $criterio, array $orden, Request $request, int $porPagina = self::POR_PAGINA): array
    {
        $total   = method_exists($repo, 'count') ? $repo->count($criterio) : count($repo->findBy($criterio));
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina  = min($paginas, max(1, $request->query->getInt('pagina', 1)));
        $items   = $repo->findBy($criterio, $orden, $porPagina, ($pagina - 1) * $porPagina);

        return $this->resultado(array_values($items), $pagina, $paginas, $total, $porPagina);
    }

    /**
     * Lo mismo sobre una consulta armada a mano, para filtros que findBy() no
     * puede expresar (p. ej. el estado calculado de un reproductor, que sale
     * de comparar su último heartbeat con el umbral).
     *
     * @return array{items: list<object>, pagina: int, paginas: int, total: int, desde: int, hasta: int}
     */
    public function paginarConsulta(QueryBuilder $qb, Request $request, int $porPagina = self::POR_PAGINA): array
    {
        $total   = (int) (clone $qb)->select('COUNT(DISTINCT ' . $qb->getRootAliases()[0] . ')')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina  = min($paginas, max(1, $request->query->getInt('pagina', 1)));
        $items   = iterator_to_array(new DoctrinePaginator(
            $qb->setFirstResult(($pagina - 1) * $porPagina)->setMaxResults($porPagina)->getQuery(),
        ), false);

        return $this->resultado($items, $pagina, $paginas, $total, $porPagina);
    }

    /**
     * @param list<object> $items
     * @return array{items: list<object>, pagina: int, paginas: int, total: int, desde: int, hasta: int}
     */
    private function resultado(array $items, int $pagina, int $paginas, int $total, int $porPagina): array
    {
        return [
            'items'   => $items,
            'pagina'  => $pagina,
            'paginas' => $paginas,
            'total'   => $total,
            'desde'   => $total === 0 ? 0 : ($pagina - 1) * $porPagina + 1,
            'hasta'   => min($total, $pagina * $porPagina),
        ];
    }
}
