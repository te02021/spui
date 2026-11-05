<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SPUI\Entity\Contenido;
use SPUI\Enum\TipoContenido;
use SPUI\Enum\TipoItemCronograma;
use SPUI\Repository\ContenidoRepository;
use SPUI\Repository\CronogramaItemRepository;
use SPUI\Service\AlcanceReproductorService;
use SPUI\Service\ComandoPublisherService;
use SPUI\Service\ImportadorCronogramaService;
use SPUI\Service\LectorPlanilla;
use SPUI\Service\PresentacionContenidoService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Importar el Excel de horarios (noviembre 2026), en el mismo modal:
 *
 *   1. Se elige el archivo (.xlsx o .csv) y se lee apenas se elige.
 *   2. Vista previa: qué se leyó de cada hoja, qué se corrigió solo y qué no
 *      se pudo leer; a qué cronograma va cada hoja (nuevo o reemplazando uno),
 *      su edificio, la vigencia y a qué hoja va cada grupo de eventos.
 *   3. Guardar: reemplaza todo junto (ImportadorCronogramaService::aplicar)
 *      y avisa a las pantallas. Las desconectadas lo reciben al reconectarse:
 *      importar no se bloquea por una Pi apagada (decisión de Mateo).
 *
 * Lo leído queda en la sesión entre el paso 2 y Guardar, con un token: así
 * no hay que volver a subir el archivo.
 */
#[Route('/spui/contenidos/importar')]
class ImportarCronogramaCmsController extends AbstractController
{
    use CsrfProtegidoTrait;
    use LimiteSubidaTrait;

    private const SESION = 'spui_importacion_cronograma';
    private const TAMANIO_MAXIMO = 10 * 1024 * 1024;

    public function __construct(
        private readonly LectorPlanilla $lector,
        private readonly ImportadorCronogramaService $importador,
        private readonly ContenidoRepository $contenidos,
        private readonly CronogramaItemRepository $items,
        private readonly PresentacionContenidoService $presentacion,
        private readonly AlcanceReproductorService $alcance,
        private readonly ComandoPublisherService $comandoPublisher,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('', name: 'spui_cms_contenidos_importar', methods: ['GET', 'POST'])]
    public function subir(Request $request): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('spui_cms_contenidos_index');
        }
        if ($request->isMethod('GET')) {
            return $this->json(['title' => 'Importar cronograma', 'html' => $this->vistaSubir()]);
        }

        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }
        if ($err = $this->excedioLimiteSubida($request)) {
            return $this->json(['success' => false, 'html' => $this->vistaSubir($err)]);
        }

        $archivo = $request->files->get('archivo');
        if (!$archivo instanceof UploadedFile || !$archivo->isValid()) {
            return $this->json(['success' => false, 'html' => $this->vistaSubir('Elegí el archivo con los horarios.')]);
        }
        if ($archivo->getSize() > self::TAMANIO_MAXIMO) {
            return $this->json(['success' => false, 'html' => $this->vistaSubir('El archivo pesa demasiado: una planilla de horarios no debería pasar de 10 MB.')]);
        }

        try {
            $plan = $this->importador->interpretar($this->lector->leer($archivo->getPathname(), $archivo->getClientOriginalName()));
        } catch (RuntimeException $e) {
            // Los mensajes de LectorPlanilla e ImportadorCronogramaService ya
            // son para mostrar; el detalle técnico, si lo hubo, va al log.
            $this->logger->info('Importar cronograma: no se pudo leer {archivo}: {error}', ['archivo' => $archivo->getClientOriginalName(), 'error' => $e->getMessage()]);
            return $this->json(['success' => false, 'html' => $this->vistaSubir($e->getMessage())]);
        } catch (\Throwable $e) {
            $this->logger->error('Importar cronograma: error leyendo {archivo}', ['archivo' => $archivo->getClientOriginalName(), 'exception' => $e]);
            return $this->json(['success' => false, 'html' => $this->vistaSubir('No se pudo leer el archivo. Revisá que sea la planilla de horarios y probá de nuevo.')]);
        }

        $token = bin2hex(random_bytes(8));
        $request->getSession()->set(self::SESION, ['token' => $token, 'plan' => $plan, 'archivo' => $archivo->getClientOriginalName()]);

        $sug = $this->importador->sugerencias($plan);
        return $this->json(['success' => false, 'html' => $this->vistaRevisar($token, $plan, $archivo->getClientOriginalName(), [
            'hojas'   => array_map(fn($s) => ['destino' => $s['destino'], 'edificio' => (string) ($s['edificio'] ?? ''), 'titulo' => ''], $sug['hojas']),
            'eventos' => $sug['eventos'],
            'desde'   => '',
            'hasta'   => '',
        ])]);
    }

    #[Route('/guardar', name: 'spui_cms_contenidos_importar_guardar', methods: ['POST'])]
    public function guardar(Request $request): Response
    {
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        $sesion = $request->getSession()->get(self::SESION);
        if (!$sesion || $sesion['token'] !== $request->request->getString('token')) {
            return $this->json(['success' => false, 'html' => $this->vistaSubir('La importación venció. Elegí el archivo de nuevo.')]);
        }
        $plan = $sesion['plan'];

        // Lo elegido, por nombre de hoja (el form lo manda por posición).
        $p = $request->request;
        $hojasForm = $p->all('hojas');
        $elegido = ['hojas' => [], 'eventos' => [], 'desde' => $p->getString('vigencia_desde'), 'hasta' => $p->getString('vigencia_hasta')];
        foreach ($plan['hojas'] as $i => $h) {
            $f = $hojasForm[$i] ?? [];
            $elegido['hojas'][$h['nombre']] = [
                'destino'  => (string) ($f['destino'] ?? 'omitir'),
                'titulo'   => trim((string) ($f['titulo'] ?? '')),
                'edificio' => (string) ($f['edificio'] ?? ''),
            ];
        }
        $eventosForm = $p->all('eventos');
        foreach ($plan['eventos']['grupos'] ?? [] as $j => $g) {
            $indice = (string) ($eventosForm[$j] ?? '');
            $elegido['eventos'][$g['edificio']] = $indice !== '' ? ($plan['hojas'][(int) $indice]['nombre'] ?? '') : '';
        }

        $errores = $this->validar($plan, $elegido, $desde, $hasta);
        if ($errores) {
            return $this->json(['success' => false, 'html' => $this->vistaRevisar($sesion['token'], $plan, $sesion['archivo'], $elegido, $errores)]);
        }

        try {
            $hechos = $this->importador->aplicar($plan, ['hojas' => $elegido['hojas'], 'eventos' => $elegido['eventos'], 'desde' => $desde, 'hasta' => $hasta], (int) $this->getUser()->getId());
        } catch (RuntimeException $e) {
            return $this->json(['success' => false, 'html' => $this->vistaRevisar($sesion['token'], $plan, $sesion['archivo'], $elegido, ['general' => $e->getMessage()])]);
        } catch (\Throwable $e) {
            $this->logger->error('Importar cronograma: no se pudo guardar', ['exception' => $e]);
            return $this->json(['success' => false, 'html' => $this->vistaRevisar($sesion['token'], $plan, $sesion['archivo'], $elegido, ['general' => 'No se pudo guardar la importación. No se cambió nada: probá de nuevo en un momento.'])]);
        }
        $request->getSession()->remove(self::SESION);

        // Avisar a las pantallas que muestran algún cronograma reemplazado.
        $reproductores = [];
        foreach ($hechos as $h) {
            foreach ($this->alcance->deContenido($h['contenido']) as $rep) {
                $reproductores[$rep->getId()] = $rep;
            }
        }
        if ($reproductores) {
            $this->comandoPublisher->pedirSyncAhora(array_values($reproductores), 'cronograma');
        }

        $partes = array_map(fn($h) => sprintf('«%s» (%d %s)', $h['contenido']->getTitulo(), $h['cantidad'], $h['cantidad'] === 1 ? 'actividad' : 'actividades'), $hechos);
        return $this->json(['success' => true, 'message' => 'Cronograma importado: ' . implode(', ', $partes) . '.']);
    }

    /** @return array<string, string> name del campo → mensaje */
    private function validar(array $plan, array $elegido, ?DateTimeImmutable &$desde, ?DateTimeImmutable &$hasta): array
    {
        $errores = [];
        $usados  = [];
        foreach ($plan['hojas'] as $i => $h) {
            $op = $elegido['hojas'][$h['nombre']];
            if ($op['destino'] === 'omitir') {
                continue;
            }
            if ($op['destino'] === 'nuevo' && $op['titulo'] === '') {
                $errores["hojas[$i][titulo]"] = 'Poné un título para el cronograma nuevo.';
            }
            if ($op['destino'] !== 'nuevo') {
                if (isset($usados[$op['destino']])) {
                    $errores["hojas[$i][destino]"] = 'Ese cronograma ya lo reemplaza otra hoja. Elegí otro destino.';
                }
                $usados[$op['destino']] = true;
            }
        }
        if (!array_filter($elegido['hojas'], fn($o) => $o['destino'] !== 'omitir')) {
            $errores['general'] = 'Elegí al menos una hoja para importar.';
        }

        $desde = $this->fecha($elegido['desde'], 'vigencia_desde', $errores);
        $hasta = $this->fecha($elegido['hasta'], 'vigencia_hasta', $errores);
        if ($desde && $hasta && $hasta < $desde) {
            $errores['vigencia_hasta'] = PresentacionContenidoService::MSG_VIGENCIA;
        }
        return $errores;
    }

    private function fecha(string $v, string $campo, array &$errores): ?DateTimeImmutable
    {
        if ($v === '') {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) {
            $errores[$campo] = 'La fecha no es válida.';
            return null;
        }
        return $d;
    }

    private function vistaSubir(?string $error = null): string
    {
        return $this->renderView('@SPUI/contenidos/importar/_subir.html.twig', [
            'error'     => $error,
            'max_bytes' => min(self::TAMANIO_MAXIMO, $this->limiteArchivoBytes(self::TAMANIO_MAXIMO)),
        ]);
    }

    private function vistaRevisar(string $token, array $plan, string $archivo, array $elegido, array $errores = []): string
    {
        // Los cronogramas que se pueden reemplazar, y qué pantallas desconectadas
        // los muestran (para avisar que lo reciben al reconectarse).
        $offline   = $this->alcance->idsOfflineDeUnaVez();
        $destinos  = [];
        foreach ($this->contenidos->findBy(['tipo' => TipoContenido::Cronograma], ['titulo' => 'ASC']) as $c) {
            /** @var Contenido $c */
            $caidos = array_values(array_map(
                fn($r) => $r->getHostname(),
                array_filter($this->alcance->deContenido($c), fn($r) => isset($offline[$r->getId()])),
            ));
            $destinos[] = [
                'contenido' => $c,
                'caidos'    => $caidos,
                // Lo que se pierde al reemplazar (los eventos, que se cargan a mano).
                'items'     => $this->items->count(['contenido' => $c]),
                'eventos'   => $this->items->count(['contenido' => $c, 'tipo' => TipoItemCronograma::Evento]),
            ];
        }

        return $this->renderView('@SPUI/contenidos/importar/_revisar.html.twig', [
            'token'     => $token,
            'plan'      => $plan,
            'archivo'   => $archivo,
            'elegido'   => $elegido,
            'err'       => $errores,
            'destinos'  => $destinos,
            'edificios' => $this->presentacion->edificios(),
        ]);
    }
}
