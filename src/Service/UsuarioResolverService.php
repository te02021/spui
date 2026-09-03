<?php

declare(strict_types=1);

namespace SPUI\Service;

use Doctrine\Persistence\ManagerRegistry;
use Shared\Entity\User;

/**
 * Resuelve el email de un usuario a partir del id crudo que guardan
 * Contenido/AlertaEmergencia/Playlist/Programacion::creadoPorId.
 *
 * Es un int simple y no una relación Doctrine porque Shared\Entity\User vive
 * en el entity manager Shared, no en SPUI — el mapeo de entidades de un EM no
 * puede referenciar clases de otro. Mismo patrón que ya usa SGP
 * (DesafioAnualController::index(), UsuarioController): pedir el EM Shared
 * explícito y consultar ahí. No hay ningún servicio o Twig extension
 * dedicado en SGP para esto — es resolución directa, y acá se puso en un
 * servicio sólo para no repetir la misma consulta en los cuatro
 * controllers que la necesitan.
 */
final class UsuarioResolverService
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
    ) {}

    /**
     * Email del usuario, o null si no existe (cuenta borrada). El caller
     * decide el fallback — en el CMS se sigue mostrando "Usuario #{id}" en
     * vez de perder el dato con un mensaje genérico.
     */
    public function email(int $userId): ?string
    {
        $user = $this->doctrine->getManager('Shared')
            ->getRepository(User::class)
            ->find($userId);

        return $user?->getEmail();
    }
}
