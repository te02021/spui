<?php

declare(strict_types=1);

namespace SPUI\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\CodigoQr;

/** @extends ServiceEntityRepository<CodigoQr> */
class CodigoQrRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CodigoQr::class);
    }

    /**
     * Suma 1 a usos_count directo en SQL y devuelve el total que quedó.
     *
     * CodigoQr::incrementarUsos() hacía $this->usosCount++ en memoria y
     * dependía de flush() para persistirlo — un read-modify-write clásico.
     * Bajo escaneos concurrentes reales (varias personas escaneando el mismo
     * cartel casi al mismo tiempo) dos requests leen el mismo valor antes de
     * que ninguno lo guarde, y el segundo flush() pisa el incremento del
     * primero: se pierden escaneos reales sin ningún error visible —
     * confirmado con 40 escaneos concurrentes reales, sólo 30 quedaron
     * contados.
     *
     * `usos_count = usos_count + 1` es atómico en el motor de MySQL: no hay
     * ventana entre leer y escribir, así que no importa cuántos escaneos
     * lleguen al mismo tiempo.
     */
    public function incrementarUsosAtomico(int $codigoId): int
    {
        $conn = $this->getEntityManager()->getConnection();
        $conn->executeStatement(
            'UPDATE codigo_qr SET usos_count = usos_count + 1 WHERE id = ?',
            [$codigoId],
        );

        return (int) $conn->fetchOne('SELECT usos_count FROM codigo_qr WHERE id = ?', [$codigoId]);
    }

    /**
     * Se "anota" para regenerar un código: pisa url_generada por la URL nueva,
     * pero SÓLO si nadie más lo cambió desde que se leyó $urlVieja.
     *
     * Existe porque ContenidoCmsController::index() puede recibir requests
     * concurrentes justo en el momento en que la dirección del CMS cambió, y
     * ContenidoQrService::sincronizarUrls() no tenía ningún lock: confirmado
     * con 8 procesos reales en paralelo, los 8 entraban a regenerar y subían 8
     * PNG distintos a S3, y sólo el que ganaba el último flush() quedaba
     * referenciado — los otros 7 quedaban huérfanos en el bucket para
     * siempre, sin que nada los fuera a borrar nunca.
     *
     * El truco es optimistic locking sobre la propia fila: MySQL sí serializa
     * los UPDATE de una misma fila, así que sólo uno de los procesos
     * concurrentes logra el UPDATE (afecta 1 fila); los demás lo ven afectar 0
     * filas —alguien más ya cambió url_generada— y se retiran ANTES de gastar
     * el trabajo real de generar el PNG y subirlo a S3. No hace falta ninguna
     * dependencia nueva (Symfony Lock, Redis, etc.): la propia fila ya sirve
     * de lock.
     *
     * "<=>" en vez de "=" porque $urlVieja puede ser NULL (código creado antes
     * de que existiera esta columna) y NULL = NULL nunca da true en SQL.
     *
     * @return bool true si esta llamada ganó la reserva y le toca regenerar.
     */
    public function reservarParaRegenerar(int $codigoId, ?string $urlVieja, string $urlNueva): bool
    {
        $conn = $this->getEntityManager()->getConnection();
        $afectadas = $conn->executeStatement(
            'UPDATE codigo_qr SET url_generada = ? WHERE id = ? AND url_generada <=> ?',
            [$urlNueva, $codigoId, $urlVieja],
        );

        return $afectadas === 1;
    }
}
