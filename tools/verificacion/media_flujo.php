<?php
/**
 * Entrega de archivos sin cargarlos en memoria (04/11/2026).
 *
 * Lee de a partes cada video, con menos memoria libre que lo que pesa el
 * archivo, y comprueba que el SHA-256 coincida con el guardado. Si alguien
 * vuelve a cargar el archivo entero, este script se queda sin memoria y falla.
 *
 * Necesita acceso al almacenamiento (S3). Sin videos cargados, se omite.
 * Uso: php tools/verificacion/media_flujo.php (sale con 1 si falla).
 */

declare(strict_types=1);

require __DIR__ . '/lib/kernel.php';

$kernel = spuiKernel();
$em = $kernel->getContainer()->get('doctrine')->getManager('SPUI');
$videos = $em->getConnection()->fetchAllAssociative(
    "SELECT titulo, ruta_archivo, hash_archivo FROM contenido WHERE tipo = 'video' AND ruta_archivo IS NOT NULL AND hash_archivo IS NOT NULL"
);
if ($videos === []) {
    echo "  OMITE  no hay videos cargados\n";
    exit(0);
}

// MediaStorageService es privado en el contenedor: se toma del locator de un controller que lo usa.
$controller = $kernel->getContainer()->get('SPUI\\Controller\\Api\\MediaController');
$media = (new ReflectionProperty($controller, 'media'))->getValue($controller);

// Menos de lo que pesa un video normal: leerlo entero no entraría.
const MARGEN = 16 * 1024 * 1024;

$fallas = 0;
foreach ($videos as $v) {
    ini_set('memory_limit', (string) (memory_get_usage(true) + MARGEN));
    $archivo = $media->abrirLectura($v['ruta_archivo']);
    if ($archivo === null) {
        $fallas++;
        printf("  FALLA  %s: no se pudo abrir\n", $v['titulo']);
        continue;
    }
    $hash = hash_init('sha256');
    $leido = 0;
    while (!feof($archivo['recurso'])) {
        $parte = fread($archivo['recurso'], 65536);
        if ($parte === false) {
            break;
        }
        $leido += strlen($parte);
        hash_update($hash, $parte);
    }
    fclose($archivo['recurso']);
    $ok = hash_final($hash) === $v['hash_archivo'] && ($archivo['tamanio'] === null || $archivo['tamanio'] === $leido);
    $fallas += $ok ? 0 : 1;
    printf(
        "  %-6s %s: %.1f MB leídos con %.0f MB de memoria libre%s\n",
        $ok ? 'OK' : 'FALLA',
        $v['titulo'],
        $leido / 1048576,
        MARGEN / 1048576,
        $leido > MARGEN ? '' : ' (el archivo es chico: no prueba nada)',
    );
}

printf("\nEntrega de archivos: %s.\n", $fallas ? "$fallas fallas" : 'todo bien');
exit($fallas ? 1 : 0);
