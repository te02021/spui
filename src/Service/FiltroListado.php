<?php

declare(strict_types=1);

namespace SPUI\Service;

use Doctrine\Persistence\ObjectRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Chips de filtro de los listados del CMS (30/10/2026).
 *
 * Un grupo es un parámetro de la URL (?estado=activos) con sus opciones; la
 * opción '' es "Todos" y es la de por defecto. Cada opción trae su criterio
 * de findBy() y su cantidad, ya combinada con los demás grupos elegidos (en
 * Contenidos, "Video 3" cuenta sólo los disponibles si está elegido
 * Disponibles). Un valor que no existe se toma como "Todos": nunca un error.
 *
 * Lo renderiza templates/_partials/filtros.html.twig.
 */
final class FiltroListado
{
    /**
     * @param array<string, array{0: string, 1: array<string, mixed>}> $opciones valor => [etiqueta, criterio]; '' = Todos
     * @param ObjectRepository|callable(array<string, mixed>): int $contar el repositorio, o
     *        BusquedaListado::contador() para que las cantidades respeten la búsqueda
     * @param array<string, mixed> $criterioOtros lo que ya eligieron los otros grupos, para las cantidades
     * @return array{param: string, etiqueta: string, actual: string, criterio: array<string, mixed>,
     *               opciones: list<array{valor: string, etiqueta: string, cuenta: int|null}>}
     */
    public function grupo(ObjectRepository|callable $contar, Request $request, string $param, string $etiqueta, array $opciones, array $criterioOtros = []): array
    {
        $actual = $request->query->getString($param);
        if (!isset($opciones[$actual])) {
            $actual = '';
        }

        $lista = [];
        foreach ($opciones as $valor => [$texto, $criterio]) {
            $lista[] = [
                'valor'    => (string) $valor,
                'etiqueta' => $texto,
                'cuenta'   => is_callable($contar) ? $contar($criterio + $criterioOtros) : $contar->count($criterio + $criterioOtros),
            ];
        }

        return [
            'param'    => $param,
            'etiqueta' => $etiqueta,
            'actual'   => $actual,
            'criterio' => $opciones[$actual][1],
            'opciones' => $lista,
        ];
    }

    /**
     * Grupo de orden: sin cantidades; cada opción trae su orderBy.
     *
     * @param array<string, array{0: string, 1: array<string, string>}> $opciones valor => [etiqueta, orden]; '' = el de por defecto
     * @return array{param: string, etiqueta: string, actual: string, orden: array<string, string>,
     *               opciones: list<array{valor: string, etiqueta: string, cuenta: null}>}
     */
    public function orden(Request $request, string $param, string $etiqueta, array $opciones): array
    {
        $actual = $request->query->getString($param);
        if (!isset($opciones[$actual])) {
            $actual = '';
        }

        return [
            'param'    => $param,
            'etiqueta' => $etiqueta,
            'actual'   => $actual,
            'orden'    => $opciones[$actual][1],
            'opciones' => array_map(
                static fn($valor, array $o) => ['valor' => (string) $valor, 'etiqueta' => $o[0], 'cuenta' => null],
                array_keys($opciones),
                $opciones,
            ),
        ];
    }
}
