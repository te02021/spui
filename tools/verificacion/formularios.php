<?php
/**
 * Auditoría de formularios: envía cada FormType del CMS con datos válidos y
 * UN campo por vez vacío. Un campo vacío tiene que terminar en "error de
 * validación" (o ser aceptado si es opcional), nunca en una excepción.
 *
 * Por qué existe: Symfony mapea los datos al objeto ANTES de validar, y un
 * setter no-nullable revienta con TypeError → HTTP 500 → un modal que "no
 * hace nada" al guardar. Pasó en agosto y volvió con 7 campos en octubre
 * (docs/README.md §3.2 y §3.4). Ningún lint lo detecta.
 *
 * También avisa (sin fallar) de los campos que el HTML marca `required` pero
 * el servidor acepta vacíos: el navegador los exige, el servidor no.
 *
 * Uso: php tools/verificacion/formularios.php   (sale con 1 si algo revienta)
 */

declare(strict_types=1);

use SPUI\Entity as E;
use SPUI\Form as F;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

require __DIR__ . '/lib/kernel.php';

$kernel = spuiKernel();
$c      = $kernel->getContainer();
date_default_timezone_set('America/Argentina/Buenos_Aires'); // lo que hace TimezoneSubscriber

$req = Request::create('/spui');
$req->setSession(new Session(new MockArraySessionStorage()));
$c->get('request_stack')->push($req);

// form.factory es privado: se toma del service locator de un controller del CMS.
$ctrl = $c->get(SPUI\Controller\Cms\PlaylistCmsController::class);
$prop = (new ReflectionObject($ctrl))->getProperty('container');
$ff   = $prop->getValue($ctrl)->get('form.factory');

$em  = $c->get('doctrine')->getManager('SPUI');
$id  = fn(string $clase): string => (string) ($em->getRepository($clase)->findOneBy([])?->getId()
    ?? throw new RuntimeException("La base no tiene ningún $clase para armar los datos de prueba."));

/** [FormType, fábrica de la entidad, datos válidos de base (sin checkboxes)] */
$tipos = [
    'Alerta'         => [F\AlertaType::class, fn() => new E\AlertaEmergencia(),
        ['titulo' => 'T', 'mensaje' => 'M', 'prioridad' => '10']],
    'Contenido'      => [F\ContenidoType::class, fn() => new E\Contenido(),
        ['titulo' => 'T', 'tipo' => 'texto', 'duracionSegundos' => '10']],
    'CronogramaItem' => [F\CronogramaItemType::class, fn() => new E\CronogramaItem(),
        ['nombre' => 'N', 'horaInicio' => '08:00', 'horaFin' => '09:00', 'diasSemana' => ['0'], 'orden' => '1']],
    'Edificio'       => [F\EdificioType::class, fn() => new E\Edificio(), ['nombre' => 'E']],
    'Pantalla'       => [F\PantallaType::class, fn() => new E\Pantalla(),
        ['nombre' => 'P', 'ubicacion' => $id(E\Ubicacion::class), 'resolucionAncho' => '1920', 'resolucionAlto' => '1080']],
    'Energia'        => [F\ProgramacionEnergeticaType::class, fn() => new E\ProgramacionEnergetica(),
        ['horaEncendido' => '08:00', 'horaApagado' => '20:00', 'nivelBrillo' => '80']],
    'Programacion'   => [F\ProgramacionType::class, fn() => new E\Programacion(),
        ['playlist' => $id(E\Playlist::class), 'horaInicio' => '08:00', 'horaFin' => '09:00',
         'fechaInicio' => '2026-10-01', 'diasSemana' => ['0'], 'prioridad' => '1']],
    'Reproductor'    => [F\ReproductorType::class, fn() => new E\Reproductor(), ['hostname' => 'spui-prueba', 'intervaloSyncMin' => '5']],
    'Ubicacion'      => [F\UbicacionType::class, fn() => new E\Ubicacion(),
        ['edificio' => $id(E\Edificio::class), 'sector' => 'S']],
];

$revientan = [];
$avisos    = [];

/** Envía el form; devuelve [form, null] o [null, mensaje de la excepción]. */
$enviar = function (string $tipo, callable $nuevo, array $datos) use ($ff): array {
    try {
        $f = $ff->create($tipo, $nuevo(), ['csrf_protection' => false]);
        $f->submit($datos, false);
        $f->isValid(); // dispara la validación completa
        return [$f, null];
    } catch (Throwable $e) {
        return [null, get_class($e) . ': ' . substr($e->getMessage(), 0, 140)];
    }
};

foreach ($tipos as $nombre => [$tipo, $nuevo, $base]) {
    [$f, $error] = $enviar($tipo, $nuevo, $base);
    if ($error !== null) { $revientan[] = "$nombre con datos válidos → $error"; continue; }
    if (!$f->isValid()) {
        $avisos[] = "$nombre: los datos de base no validan (" . trim(strip_tags((string) $f->getErrors(true))) . ') — corregir la base de prueba';
    }

    // Campos que el HTML marca required (el navegador los exigiría).
    $requeridosHtml = [];
    foreach ($f->createView()->children as $k => $hijo) {
        if (($hijo->vars['required'] ?? false) && !in_array('hidden', $hijo->vars['block_prefixes'], true)) $requeridosHtml[] = $k;
    }

    foreach (array_keys($base) as $campo) {
        $datos = $base;
        $datos[$campo] = is_array($base[$campo]) ? [] : '';
        [$f, $error] = $enviar($tipo, $nuevo, $datos);
        if ($error !== null) {
            $revientan[] = "$nombre.$campo vacío → $error";
            printf("  REVIENTA %-15s %-18s %s\n", $nombre, $campo, $error);
            continue;
        }
        $conError = count($f->get($campo)->getErrors(true)) + count($f->getErrors(false)) > 0;
        printf("  OK       %-15s %-18s vacío → %s\n", $nombre, $campo, $conError ? 'error de validación' : 'aceptado (opcional en el servidor)');
        if (!$conError && in_array($campo, $requeridosHtml, true)) {
            $avisos[] = "$nombre.$campo: el HTML lo marca obligatorio pero el servidor lo acepta vacío";
        }
    }
}

foreach ($avisos as $a) echo "  AVISO  $a\n";
printf("\nFormularios: %d tipos, %d campos que revientan, %d avisos.\n", count($tipos), count($revientan), count($avisos));
foreach ($revientan as $r) echo "  ✗ $r\n";
exit($revientan ? 1 : 0);
