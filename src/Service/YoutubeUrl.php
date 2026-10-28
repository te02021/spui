<?php

declare(strict_types=1);

namespace SPUI\Service;

/**
 * Links de YouTube: reconoce las formas en que se copian (watch?v=, youtu.be,
 * shorts, embed, live, con o sin www/m y con parámetros) y los deja todos como
 * https://www.youtube.com/watch?v=<id>.
 *
 * El reproductor descarga el video con yt-dlp a partir de ese link
 * (pi-client/spui/youtube.py) y lo guarda en el caché con el id como nombre:
 * normalizarlo acá hace que dos formas del mismo link sean el mismo archivo, y
 * que un texto que no es un link de YouTube se rechace al guardarlo y no
 * recién en la pantalla.
 */
final class YoutubeUrl
{
    private const ID = '[A-Za-z0-9_-]{11}';

    /** El id de 11 caracteres, o null si no es un link de YouTube reconocible. */
    public static function id(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://' . $url;
        }
        $partes = parse_url($url);
        $host = strtolower($partes['host'] ?? '');
        $host = preg_replace('/^(www\.|m\.|music\.)/', '', $host);
        $ruta = $partes['path'] ?? '';

        if ($host === 'youtu.be' && preg_match('#^/(' . self::ID . ')#', $ruta, $m)) {
            return $m[1];
        }
        if (!in_array($host, ['youtube.com', 'youtube-nocookie.com'], true)) {
            return null;
        }
        if ($ruta === '/watch') {
            parse_str($partes['query'] ?? '', $q);
            $v = is_string($q['v'] ?? null) ? $q['v'] : '';
            return preg_match('#^' . self::ID . '$#', $v) ? $v : null;
        }
        if (preg_match('#^/(?:shorts|embed|live|v)/(' . self::ID . ')(?:[/?]|$)#', $ruta, $m)) {
            return $m[1];
        }
        return null;
    }

    public static function normalizar(string $url): ?string
    {
        $id = self::id($url);
        return $id === null ? null : 'https://www.youtube.com/watch?v=' . $id;
    }
}
