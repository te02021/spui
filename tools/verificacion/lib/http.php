<?php
/**
 * Helpers HTTP de la suite: pedidos contra el Apache real con la sesión de
 * prueba, y la detección de respuestas rotas.
 */

declare(strict_types=1);

/** Crea una sesión de prueba nueva corriendo sesion.php y devuelve su id. */
function spuiNuevaSesion(): string
{
    $salida = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../sesion.php'), $salida, $codigo);
    $id = trim((string) end($salida));
    if ($codigo !== 0 || !preg_match('/^[a-zA-Z0-9,-]{22,}$/', $id)) {
        fwrite(STDERR, "No se pudo generar la sesión de prueba.\n" . implode("\n", $salida) . "\n");
        exit(2);
    }
    return $id;
}

/**
 * @return array{status:int, tipo:string, cuerpo:string, redirect:string}
 */
function spuiHttp(string $metodo, string $url, ?string $sesion, bool $xhr, array $campos = []): array
{
    $ch = curl_init($url);
    $headers = ['Accept: text/html,application/json'];
    if ($xhr) $headers[] = 'X-Requested-With: XMLHttpRequest';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => $metodo,
    ]);
    if ($sesion !== null) curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . $sesion);
    if ($metodo === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($campos));
    $cuerpo = curl_exec($ch);
    $r = [
        'status'   => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'tipo'     => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
        'cuerpo'   => $cuerpo === false ? '' : $cuerpo,
        'redirect' => (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL),
    ];
    if ($cuerpo === false) $r['status'] = 0;
    curl_close($ch);
    return $r;
}

/** Devuelve la descripción del problema, o null si la respuesta está bien. */
function spuiProblemaDeRespuesta(array $r, bool $xhr, bool $binario = false): ?string
{
    if ($r['status'] === 0) return 'sin respuesta (¿Apache caído?)';
    if ($r['status'] === 302 && str_contains($r['redirect'], 'unraf.edu.ar')) return 'redirige al login: la sesión de prueba no sirvió';
    if ($r['status'] !== 200) return "HTTP {$r['status']}" . spuiMensajeExcepcion($r['cuerpo']);
    if ($binario) return null;

    $html = $r['cuerpo'];
    if ($xhr && str_contains($r['tipo'], 'json')) {
        $json = json_decode($r['cuerpo'], true);
        if (!is_array($json)) return 'JSON inválido';
        if (($json['success'] ?? true) === false) return 'success:false — ' . ($json['message'] ?? '(sin mensaje)');
        $html = (string) ($json['html'] ?? '');
        if ($html === '') return 'JSON sin html';
    }
    if ($m = spuiMensajeExcepcion($html)) return 'página de excepción' . $m;
    return null;
}

/** Extrae el mensaje de una página de error de Symfony, si lo es. */
function spuiMensajeExcepcion(string $html): string
{
    if (!str_contains($html, 'exception-message') && !str_contains($html, 'sf-dump') && !str_contains($html, 'Twig\\Error')) {
        return '';
    }
    if (preg_match('#<h1[^>]*class="[^"]*exception-message[^"]*"[^>]*>(.*?)</h1>#s', $html, $m)) {
        return ': ' . trim(html_entity_decode(strip_tags($m[1])));
    }
    if (preg_match('#<title>(.*?)</title>#s', $html, $m)) return ': ' . trim(html_entity_decode($m[1]));
    return ' (sin mensaje)';
}
