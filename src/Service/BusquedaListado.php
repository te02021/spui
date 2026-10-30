<?php

declare(strict_types=1);

namespace SPUI\Service;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * Buscador de los listados del CMS (30/10/2026): el texto de ?q= contra
 * los campos de cada sección, combinado con los chips de filtro.
 *
 * Arma la consulta del listado (para Paginador::paginarConsulta()) y la
 * función que cuenta cada chip, para que las cantidades respeten lo que se
 * está buscando. Los campos se nombran desde la entidad del listado, con
 * alias "e": 'e.nombre', o 'ed.nombre' si se declara el join ['ed' =>
 * 'e.edificio'] (sólo relaciones a uno: un join a muchos repetiría filas).
 * Los comodines de LIKE que escriba el usuario se toman como texto.
 */
final class BusquedaListado
{
    public const LARGO_MAXIMO = 100;

    public function texto(Request $request): string
    {
        return mb_substr(trim($request->query->getString('q')), 0, self::LARGO_MAXIMO);
    }

    /**
     * @param array<string, mixed>  $criterio campo de "e" => valor, como en findBy()
     * @param list<string>          $campos   dónde buscar
     * @param array<string, string> $joins    alias => relación
     * @param array<string, string> $orden    campo de "e" => ASC|DESC
     */
    public function consulta(EntityRepository $repo, array $criterio, string $q, array $campos, array $joins = [], array $orden = []): QueryBuilder
    {
        $qb = $repo->createQueryBuilder('e');
        foreach ($joins as $alias => $relacion) {
            $qb->leftJoin($relacion, $alias);
        }
        foreach ($criterio as $campo => $valor) {
            $param = 'c_' . $campo;
            $qb->andWhere("e.$campo = :$param")->setParameter($param, $valor instanceof \BackedEnum ? $valor->value : $valor);
        }
        if ($q !== '' && $campos !== []) {
            $qb->andWhere('(' . implode(' OR ', array_map(static fn(string $c) => "$c LIKE :q", $campos)) . ')')
               ->setParameter('q', '%' . addcslashes($q, '%_\\') . '%');
        }
        foreach ($orden as $campo => $sentido) {
            $qb->addOrderBy("e.$campo", $sentido);
        }
        return $qb;
    }

    /**
     * Para FiltroListado: cuántos hay con un criterio, respetando la búsqueda.
     *
     * @param list<string>          $campos
     * @param array<string, string> $joins
     * @return callable(array<string, mixed>): int
     */
    public function contador(EntityRepository $repo, string $q, array $campos, array $joins = []): callable
    {
        return fn(array $criterio): int => (int) $this->consulta($repo, $criterio, $q, $campos, $joins)
            ->select('COUNT(DISTINCT e.id)')->getQuery()->getSingleScalarResult();
    }
}
