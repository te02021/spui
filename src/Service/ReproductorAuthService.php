<?php

declare(strict_types=1);

namespace SPUI\Service;

use SPUI\Entity\Reproductor;
use SPUI\Repository\ReproductorRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Valida la API key enviada por los reproductores Raspberry Pi en el header X-Api-Key.
 * La key raw se hashea con SHA-256 y se compara contra el hash almacenado.
 */
final class ReproductorAuthService
{
    public function __construct(
        private readonly ReproductorRepository $repo,
    ) {}

    public function autenticar(Request $request): ?Reproductor
    {
        $rawKey = trim((string) $request->headers->get('X-Api-Key', ''));
        if ($rawKey === '') {
            return null;
        }

        return $this->repo->findByApiKeyHash($rawKey);
    }

    public function autenticarOFallar(Request $request): Reproductor
    {
        $reproductor = $this->autenticar($request);
        if ($reproductor === null) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException(
                'X-Api-Key',
                'API key inválida o ausente.',
            );
        }

        return $reproductor;
    }
}
