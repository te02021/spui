<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Reproductor;
use SPUI\Form\ReproductorType;
use SPUI\Repository\ReproductorRepository;
use SPUI\Repository\TelemetriaHoraRepository;
use SPUI\Repository\TelemetriaRepository;
use SPUI\Service\AlcanceReproductorService;
use SPUI\Service\MqttSecurityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/spui/reproductores')]
class ReproductorCmsController extends AbstractController
{
    use BloqueoOfflineTrait;
    use CsrfProtegidoTrait;

    /** Rangos ofrecidos en el detalle de telemetría: etiqueta => horas hacia atrás. */
    private const RANGOS = ['24h' => 24, '7d' => 168, '30d' => 720];

    public function __construct(
        private readonly ReproductorRepository $repo,
        private readonly TelemetriaRepository $telemetriaRepo,
        private readonly TelemetriaHoraRepository $rollupRepo,
        private readonly AlcanceReproductorService $alcance,
        private readonly MqttSecurityService $mqttSecurity,
        private readonly ManagerRegistry $doctrine,
        #[Autowire('%env(float:default:spui_temp_alerta_default:SPUI_TEMP_ALERTA_CELSIUS)%')]
        private readonly float $tempAlertaCelsius = 80.0,
    ) {}

    private function em()
    {
        return $this->doctrine->getManager('SPUI');
    }

    #[Route('', name: 'spui_cms_reproductores_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@SPUI/reproductores/index.html.twig', [
            'reproductores' => $this->repo->findBy([], ['hostname' => 'ASC']),
            // Se resuelve una sola vez por request: consultarlo por fila
            // dispararía la cadena completa de relaciones por cada reproductor.
            'ids_offline'   => $this->alcance->idsOfflineDeUnaVez(),
        ]);
    }

    /**
     * Histórico de telemetría de un nodo.
     *
     * Es pantalla propia y no modal porque son gráficos que uno se queda
     * mirando un rato, a diferencia del resto del ABM.
     */
    #[Route('/{id}/telemetria', name: 'spui_cms_reproductores_telemetria', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function telemetria(int $id, Request $request): Response
    {
        $reproductor = $this->repo->find($id);
        if (!$reproductor) { throw $this->createNotFoundException(); }

        $rango = $request->query->getString('rango', '24h');
        if (!isset(self::RANGOS[$rango])) {
            $rango = '24h';
        }
        $horas = self::RANGOS[$rango];
        $desde = new DateTimeImmutable(sprintf('-%d hours', $horas));

        // De dónde salen los datos según el rango:
        //
        // - 24 h → de la telemetría cruda. Está completa (la retención del
        //   crudo es de varios días) e incluye la hora en curso, que el rollup
        //   todavía no consolidó.
        // - 7 d y 30 d → del rollup horario. Más allá de la retención del crudo
        //   las lecturas ya no existen, así que leerlas de ahí devolvería una
        //   serie truncada sin ningún aviso.
        //
        // Ambos repositorios devuelven el mismo shape, así que el template no
        // se entera de la diferencia.
        $usarRollup = $horas > self::RANGOS['24h'];

        $serie = $usarRollup
            ? $this->rollupRepo->findSerie($reproductor, $desde)
            : $this->telemetriaRepo->findSeriePorHora($reproductor, $desde);

        $resumen = $usarRollup
            ? $this->rollupRepo->resumen($reproductor, $desde)
            : $this->telemetriaRepo->resumen($reproductor, $desde);

        return $this->render('@SPUI/reproductores/telemetria.html.twig', [
            'reproductor' => $reproductor,
            'serie'       => $serie,
            'resumen'     => $resumen,
            'ultima'      => $this->telemetriaRepo->findUltimos($reproductor, 1)[0] ?? null,
            'rango'       => $rango,
            'rangos'      => array_keys(self::RANGOS),
            'temp_umbral' => $this->tempAlertaCelsius,
        ]);
    }

    #[Route('/{id}/ver', name: 'spui_cms_reproductores_ver', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function ver(int $id, Request $request): Response
    {
        $reproductor = $this->repo->find($id);
        if (!$reproductor) { throw $this->createNotFoundException(); }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => $reproductor->getHostname(),
                'html'  => $this->renderView('@SPUI/reproductores/_view.html.twig', ['reproductor' => $reproductor]),
            ]);
        }

        return $this->redirectToRoute('spui_cms_reproductores_index');
    }

    #[Route('/nuevo', name: 'spui_cms_reproductores_nuevo', methods: ['GET', 'POST'])]
    public function nuevo(Request $request): Response
    {
        $reproductor = new Reproductor();
        $form = $this->createForm(ReproductorType::class, $reproductor, [
            'action' => $this->generateUrl('spui_cms_reproductores_nuevo'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $rawKey = bin2hex(random_bytes(32));
            $reproductor->setApiKeyHash(hash('sha256', $rawKey));
            $this->em()->persist($reproductor);
            $this->em()->flush();

            // El flush va antes: si el broker no responde, el reproductor ya
            // quedó registrado y funcionando por HTTP. Lo único que falta es su
            // credencial MQTT, que se recrea volviendo a regenerar la clave.
            $mqttOk = $this->mqttSecurity->sincronizar($reproductor, $rawKey);

            // La clave se muestra una única vez, en el modal (showApiKeyModal).
            return $this->json([
                'success'  => true,
                'apiKey'   => $rawKey,
                'hostname' => $reproductor->getHostname(),
                'mqttOk'   => $mqttOk,
                'message'  => 'Reproductor "' . $reproductor->getHostname() . '" registrado.'
                    . ($mqttOk ? '' : ' Atención: no se pudo crear su credencial en el broker MQTT'
                        . ' (¿Mosquitto corriendo?). El equipo va a funcionar, pero sin alertas'
                        . ' instantáneas hasta que regeneres la clave con el broker disponible.'),
            ]);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Nuevo reproductor',
                'html'  => $this->renderView('@SPUI/reproductores/_form.html.twig', ['form' => $form]),
            ]);
        }

        return $this->redirectToRoute('spui_cms_reproductores_index');
    }

    #[Route('/{id}/editar', name: 'spui_cms_reproductores_editar', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function editar(int $id, Request $request): Response
    {
        $reproductor = $this->repo->find($id);
        if (!$reproductor) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(ReproductorType::class, $reproductor, [
            'action' => $this->generateUrl('spui_cms_reproductores_editar', ['id' => $id]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($r = $this->bloquearSiOffline([$reproductor], $request, 'spui_cms_reproductores_index')) {
                return $r;
            }

            $this->em()->flush();
            $msg = 'Reproductor "' . $reproductor->getHostname() . '" actualizado.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'message' => $msg]);
            }
            $this->addFlash('success', $msg);
            return $this->redirectToRoute('spui_cms_reproductores_index');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Editar: ' . $reproductor->getHostname(),
                'html'  => $this->renderView('@SPUI/reproductores/_form.html.twig', ['form' => $form, 'reproductor' => $reproductor]),
            ]);
        }

        return $this->redirectToRoute('spui_cms_reproductores_index');
    }

    #[Route('/{id}/regenerar-clave', name: 'spui_cms_reproductores_regenerar_clave', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function regenerarClave(int $id, Request $request): Response
    {
        $reproductor = $this->repo->find($id);
        if (!$reproductor) {
            throw $this->createNotFoundException();
        }
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        // De todas las acciones del CMS, ésta es la que peor tolera que el
        // equipo esté offline: la clave nueva hay que copiarla a mano al
        // spui.env de la Raspberry, y hasta que alguien lo haga físicamente el
        // reproductor queda sin poder autenticarse.
        if ($r = $this->bloquearSiOffline([$reproductor], $request, 'spui_cms_reproductores_index')) {
            return $r;
        }

        // Se guarda ANTES de pisarla: tarea 1.4, para poder distinguir en el
        // dashboard un intento con esta clave vieja (la Pi real, con el .env
        // sin actualizar) de un intento genuinamente desconocido.
        $reproductor->marcarHashAnterior($reproductor->getApiKeyHash());

        $rawKey = bin2hex(random_bytes(32));
        $reproductor->setApiKeyHash(hash('sha256', $rawKey));
        $this->em()->flush();

        // Obligatorio acá: la contraseña MQTT se deriva de la API key, así que
        // regenerarla sin sincronizar el broker dejaría al equipo autenticando
        // bien por HTTP pero rechazado en MQTT — se quedaría sin alertas sin
        // ningún error visible en el CMS.
        $mqttOk = $this->mqttSecurity->sincronizar($reproductor, $rawKey);

        // La clave nueva se muestra una única vez, en el modal (showApiKeyModal).
        return $this->json([
            'success'  => true,
            'apiKey'   => $rawKey,
            'hostname' => $reproductor->getHostname(),
            'mqttOk'   => $mqttOk,
            'message'  => 'Clave API regenerada para "' . $reproductor->getHostname() . '".'
                . ' Acordate de actualizar SPUI_API_KEY en el spui.env de la Raspberry'
                . ' y reiniciar el servicio, o el equipo va a quedar sin comunicación.'
                . ($mqttOk ? '' : ' Atención: no se pudo actualizar su credencial en el broker'
                    . ' MQTT (¿Mosquitto corriendo?). Volvé a regenerar la clave cuando esté'
                    . ' disponible.'),
        ]);
    }

    #[Route('/{id}/eliminar', name: 'spui_cms_reproductores_eliminar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function eliminar(int $id, Request $request): Response
    {
        $reproductor = $this->repo->find($id);
        if (!$reproductor) {
            throw $this->createNotFoundException();
        }
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        if (!$reproductor->getPantallas()->isEmpty()) {
            $msg = 'No se puede eliminar: tiene ' . $reproductor->getPantallas()->count() . ' pantalla(s) asignada(s). Desasignálas primero desde cada pantalla.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => $msg], 422);
            }
            $this->addFlash('error', $msg);
            return $this->redirectToRoute('spui_cms_reproductores_index');
        }

        if ($r = $this->bloquearSiOffline([$reproductor], $request, 'spui_cms_reproductores_index')) {
            return $r;
        }

        // Revocar antes de borrar la fila: después ya no se puede derivar el
        // usuario MQTT, y la credencial quedaría válida en el broker para
        // siempre — un equipo dado de baja podría seguir conectándose.
        //
        // El resultado NO se descarta: si el broker está caído, la revocación
        // falla y la credencial queda huérfana sin posibilidad de limpiarla
        // después (el usuario se deriva del id, que está por desaparecer). Hay
        // que avisarlo, igual que hacen crear y regenerar-clave.
        $usuarioMqtt = $reproductor->mqttUsuario();
        $mqttOk      = $this->mqttSecurity->revocar($reproductor);

        $hostname = $reproductor->getHostname();
        $this->em()->remove($reproductor);
        $this->em()->flush();

        $msg = 'Reproductor "' . $hostname . '" eliminado.';
        if (!$mqttOk) {
            $msg .= ' Atención: no se pudo revocar su credencial en el broker MQTT'
                . ' (¿Mosquitto corriendo?). El usuario "' . $usuarioMqtt . '" sigue'
                . ' habilitado y hay que borrarlo a mano con:'
                . ' mosquitto_ctrl -u spui-admin -h 127.0.0.1 dynsec deleteClient ' . $usuarioMqtt;
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'success' => true,
                'mqttOk'  => $mqttOk,
                'type'    => $mqttOk ? 'success' : 'warning',
                'message' => $msg,
            ]);
        }
        $this->addFlash($mqttOk ? 'success' : 'warning', $msg);
        return $this->redirectToRoute('spui_cms_reproductores_index');
    }
}
