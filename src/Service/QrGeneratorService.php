<?php

declare(strict_types=1);

namespace SPUI\Service;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Genera imágenes QR en PNG usando endroid/qr-code, y decide qué URL codifican.
 *
 * Único punto que sabe lo que un QR de SPUI lleva adentro: la URL de redirect
 * del CMS (/api/spui/qr/{id}/r), nunca la URL destino. Por eso cada escaneo
 * pasa por el CMS, incrementa usos_count, y se puede cambiar el destino sin
 * reimprimir nada.
 */
final class QrGeneratorService
{
    private const SIZE   = 300;
    private const MARGIN = 10;

    /**
     * Dirección a la que se "conecta" el socket de detección de IP. Es de
     * TEST-NET-3 (RFC 5737, reservada para documentación): no existe y no se
     * le manda nada — ver ipDeRed().
     */
    private const SONDA_RUTA = 'udp://198.51.100.1:53';

    /** IP de red detectada, cacheada por proceso. false = todavía sin buscar. */
    private static string|false|null $ipCache = false;

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RequestStack $requestStack,
    ) {}

    /**
     * URL de redirect que codifica el PNG de un código.
     *
     * **Por qué no se usa el host del request.** Quien escanea un QR es un
     * celular cualquiera de la red: no tiene la entrada `intranet` en su
     * /etc/hosts —que es como llegan al CMS tanto el operador como la Pi (ver
     * `docs/09_instalacion_raspberry.md`)— ni tiene por qué estar en la misma
     * zona DNS. Un QR generado navegando `http://intranet/` queda con ese
     * nombre adentro para siempre y no lo puede abrir nadie; uno generado
     * desde `localhost`, o desde consola donde no hay request, es peor todavía:
     * cada celular resuelve `localhost` a sí mismo.
     *
     * Lo único que cualquier dispositivo de la red puede usar es la **IP del
     * equipo donde corre el CMS**, y esa se detecta sola (ver ipDeRed()). Como
     * el CMS vive en un solo equipo por entorno —esta PC en desarrollo, la VM
     * en producción— eso no necesita configurarse en ningún lado, y mudar el
     * CMS de máquina no pide tocar nada: los PNG viejos los detecta y rehace
     * solo `ContenidoQrService::sincronizarUrls()`, sin comandos ni variables
     * de entorno. En producción la base es el dominio con su prefijo, que sale
     * igual de acá, del request.
     */
    public function urlRedirect(int $codigoId): string
    {
        return $this->baseUrl()
             . $this->urlGenerator->generate('spui_qr_redirect', ['id' => $codigoId]);
    }

    /**
     * Si la URL que se está codificando ahora la puede abrir un celular ajeno.
     *
     * Da false cuando la detección de IP falló y quedó el host del request
     * (`intranet`, `localhost`): los PNG salen con un nombre que sólo resuelve
     * desde este equipo y no los abre nadie. El panel lo avisa; sin este
     * chequeo el problema sólo se descubre con el cartel ya impreso.
     */
    public function urlRedirectEsAlcanzable(): bool
    {
        return self::hostResolublePorCualquiera((string) parse_url($this->baseUrl(), PHP_URL_HOST));
    }

    /**
     * Qué tan pública es una URL ya generada. Más chico = mejor.
     *
     * Es la regla que evita que los PNG oscilen. Un contenido QR que está en
     * la playlist de un reproductor cambia de archivo cada vez que se
     * regenera, y la Pi resuelve el archivo local por el nombre que viene en
     * url_descarga: si el nombre cambia y la descarga no llegó a tiempo, esa
     * pantalla muestra la de espera. O sea, regenerar de más se ve.
     *
     * Y hay un caso en que pasaría solo: en producción el CMS se alcanza por
     * el dominio, pero también por la IP de la VM. Dos operadores entrando por
     * direcciones distintas harían ping-pong con los PNG indefinidamente. Por
     * eso una base nunca se reemplaza por otra de rango peor: de la IP se
     * puede subir al dominio, del dominio no se baja a la IP.
     *
     * 1 = https + nombre de host   (producción)
     * 2 = http  + nombre de host
     * 3 = IP                       (desarrollo)
     */
    public static function rangoDeBase(string $url): int
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (!self::hostResolublePorCualquiera($host) || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return 3;
        }

        return str_starts_with(strtolower($url), 'https://') ? 1 : 2;
    }

    /**
     * Esquema + host (+ puerto si no es el estándar), sin barra final.
     *
     * Pública porque el panel la necesita como clave: es lo que compara para
     * saber si vale la pena recorrer los códigos buscando desactualizados.
     */
    public function baseUrl(): string
    {
        $request = $this->requestStack->getMainRequest();

        // Si el CMS ya se está navegando por una dirección que cualquiera
        // resuelve, esa gana: es la que el CMS quiere mostrar, y la única que
        // puede tener un certificado válido si se sirve por HTTPS. Poner la IP
        // ahí sería un paso atrás — https contra una IP le muestra al que
        // escanea una advertencia de certificado antes de llegar al destino.
        if ($request !== null && self::hostResolublePorCualquiera($request->getHost())) {
            return $request->getSchemeAndHttpHost();
        }

        $ip = self::ipDeRed();
        if ($ip === null) {
            // Sin ninguna interfaz de red utilizable no hay nada mejor que el
            // host del request (o el del contexto del router, en consola).
            return $request !== null
                ? $request->getSchemeAndHttpHost()
                : $this->urlGenerator->getContext()->getScheme() . '://' . $this->urlGenerator->getContext()->getHost();
        }

        // Siempre http: una IP privada no puede tener un certificado válido, y
        // el redirect es un 302 público sin nada sensible — forzar https sólo
        // lograría la advertencia de seguridad que se acaba de evitar arriba.
        // El puerto sí se arrastra del request: si el CMS no está en el 80, el
        // QR tiene que llevarlo o no abre.
        $puerto = $request?->getPort();
        $sufijo = ($puerto === null || $puerto === 80 || $puerto === 443) ? '' : ':' . $puerto;

        return 'http://' . $ip . $sufijo;
    }

    /**
     * Si un dispositivo cualquiera de la red puede resolver ese host por su
     * cuenta.
     *
     * Una IP, sí. Un nombre con dominio (intranet.unraf.edu.ar), sí: sale del
     * DNS. Un nombre corto (`intranet`, `localhost`) o de red local (`.local`,
     * `.lan`), no: sale del /etc/hosts o del mDNS del propio equipo, y así es
     * justamente como llegan al CMS tanto el operador como la Pi — pero no el
     * celular de un estudiante.
     */
    private static function hostResolublePorCualquiera(string $host): bool
    {
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        $host = strtolower($host);
        if (!str_contains($host, '.')) {
            return false;
        }

        foreach (['.local', '.lan', '.home', '.internal', '.localdomain'] as $sufijo) {
            if (str_ends_with($host, $sufijo)) {
                return false;
            }
        }

        return true;
    }

    /**
     * IP del equipo en la red local, o null si no se pudo averiguar.
     *
     * "Conectar" un socket UDP no manda ningún paquete: sólo hace que el
     * sistema operativo elija por qué interfaz saldría el tráfico hacia esa
     * dirección. Leyendo el extremo local del socket queda la IP de esa
     * interfaz — la de la red de verdad, y no la de loopback ni la del
     * adaptador host-only de VirtualBox, que es lo que devuelve
     * gethostbyname(gethostname()) en este equipo.
     *
     * Se cachea por proceso: en el CMS son microsegundos, pero
     * /api/spui/qr/{id}/imagen lo llama en cada pedido.
     */
    private static function ipDeRed(): ?string
    {
        if (self::$ipCache !== false) {
            return self::$ipCache;
        }

        self::$ipCache = null;

        $socket = @stream_socket_client(self::SONDA_RUTA, $errno, $errstr, 1);
        if ($socket !== false) {
            $local = @stream_socket_get_name($socket, false);
            @fclose($socket);

            // 'ip:puerto' — se corta por el último ':' para no romper IPv6.
            if (is_string($local) && ($corte = strrpos($local, ':')) !== false) {
                self::$ipCache = self::ipUsable(substr($local, 0, $corte));
            }
        }

        // Último recurso, sólo si el socket no dijo nada: puede devolver la IP
        // de una interfaz virtual, pero es preferible a no tener ninguna.
        if (self::$ipCache === null) {
            $host = gethostname();
            if ($host !== false) {
                self::$ipCache = self::ipUsable(@gethostbyname($host));
            }
        }

        return self::$ipCache;
    }

    /** La IP si sirve para que otro equipo llegue hasta acá, o null. */
    private static function ipUsable(string $ip): ?string
    {
        $valida = filter_var(
            trim($ip, '[]'),
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_RES_RANGE,   // descarta loopback, link-local y 0.0.0.0
        );

        return $valida === false ? null : $valida;
    }

    public function generarPng(string $url, int $size = self::SIZE): string
    {
        $qrCode = new QrCode(
            data:   $url,
            size:   $size,
            margin: self::MARGIN,
        );

        return (new PngWriter())->write($qrCode)->getString();
    }

    public function mimeType(): string
    {
        return 'image/png';
    }
}
