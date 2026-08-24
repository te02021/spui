// Tiempos relativos que se mantienen al dia sin ir al servidor.
//
// "Hace 2 min" se calculaba al renderizar el Twig, asi que quedaba congelado:
// una pantalla abierta media hora seguia diciendo "Hace 2 min" sobre un equipo
// que llevaba 32 minutos sin reportar. Justo el dato que uno mira para decidir
// si un reproductor esta vivo.
//
// Uso desde Twig:
//     <span data-spui-desde="{{ fecha|date('c') }}">Hace un rato</span>
//
// El contenido inicial lo sigue poniendo el servidor, asi que sin JS la pagina
// se ve bien igual; el modulo solo lo mantiene fresco.
(function () {
    'use strict';

    window.spui = window.spui || {};

    // Cada 30 s. La unidad mas chica que se muestra es el minuto, asi que
    // recalcular mas seguido no cambiaria ni un caracter en pantalla.
    var INTERVALO_MS = 30000;

    /**
     * Texto legible para una diferencia en segundos.
     *
     * NO se muestran segundos a proposito. Como el heartbeat llega cada 60 s,
     * un contador al segundo subiria "hace 12 s", "hace 22 s"... hasta "hace
     * 58 s" y de golpe volveria a cero al renovarse: texto en movimiento
     * permanente, con un salto brusco cada minuto, sobre un dato cuya
     * precision util es del orden del minuto.
     *
     * El primer minuto entero se muestra como "hace instantes": mientras el
     * equipo reporta con normalidad, el texto queda quieto y solo se mueve
     * cuando empieza a atrasarse, que es justo cuando hay que mirarlo.
     *
     * Se corta en meses porque mas alla el dato deja de ser util para decidir
     * si un equipo esta vivo: si lleva semanas sin reportar, lo que importa es
     * que esta caido, no cuantas semanas.
     */
    function formatear(segundos) {
        // Reloj del cliente adelantado respecto del servidor: no tiene sentido
        // mostrar un tiempo negativo.
        if (segundos < 0)    return 'hace instantes';

        if (segundos < 90)   return 'hace instantes';

        var minutos = Math.round(segundos / 60);
        if (minutos < 60)    return 'hace ' + minutos + ' min';

        var horas = Math.round(minutos / 60);
        if (horas === 1)     return 'hace 1 h';
        if (horas < 24)      return 'hace ' + horas + ' h';

        var dias = Math.round(horas / 24);
        if (dias === 1)      return 'ayer';
        if (dias < 30)       return 'hace ' + dias + ' días';

        return 'hace más de un mes';
    }

    function actualizar() {
        var ahora = Date.now();

        document.querySelectorAll('[data-spui-desde]').forEach(function (el) {
            var iso = el.getAttribute('data-spui-desde');
            if (!iso) return;

            var ts = Date.parse(iso);
            if (isNaN(ts)) return;   // fecha mal formada: se deja lo que puso el servidor

            var segundos = Math.floor((ahora - ts) / 1000);
            el.textContent = formatear(segundos);

            // Marca para que el CSS pueda resaltar equipos sin reportar. El
            // umbral coincide con el que usa spui:mantenimiento para dar por
            // caido un reproductor.
            el.classList.toggle('is-stale', segundos > 300);
        });
    }

    window.spui.actualizarTiempos = actualizar;

    document.addEventListener('DOMContentLoaded', function () {
        actualizar();
        setInterval(actualizar, INTERVALO_MS);
    });
}());
