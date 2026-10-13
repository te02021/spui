// Avisos de conexión de reproductores, en vivo.
//
// En Pantallas, Contenidos, Playlists y Programación los botones de una fila se
// bloquean si afectan a un reproductor desconectado. Sin esto, cuando el equipo
// volvía el botón seguía bloqueado hasta recargar la página (y al revés: si se
// caía, seguía habilitado hasta el 409 del servidor).
//
// El CMS publica en spui/cms/conexion/{id} cada vez que un reproductor cambia de
// estado — después de grabarlo (ConexionPublisherService) — y acá el navegador,
// suscrito por WebSocket, vuelve a pedir la tabla. No hay ningún timer: mientras
// nada cambia, la página no manda un solo byte.
//
// El mensaje no se interpreta: sólo dispara el pedido. Lo que se muestra, y qué
// queda bloqueado, lo decide el servidor al responder, igual que al cargar la
// página. La conexión y las credenciales (sólo lectura) llegan en los data-*
// que emite _partials/conexion_vivo.html.twig.
(function () {
    'use strict';

    // Varios reproductores pueden cambiar a la vez (el mantenimiento marca a
    // todos los caídos de un edificio en una pasada): se agrupan los avisos
    // que llegan juntos en un solo pedido.
    var ESPERA_MS = 400;

    var espera    = null;
    var pendiente = false;

    function refrescar() {
        var ar = window.spui && window.spui.autoRefresh;
        if (!ar) return;

        // Con un modal abierto no se toca la tabla de atrás (el mismo criterio
        // que el refresco por intervalo: se podría estar escribiendo en un
        // formulario de esa fila). Se hace al cerrarlo. Con la pestaña oculta,
        // auto-refresh.js ya refresca al volver a ella.
        if (document.hidden || ar.hayModalAbierto()) {
            pendiente = true;
            return;
        }
        pendiente = false;
        ar.refrescar();
    }

    function programar() {
        if (espera) clearTimeout(espera);
        espera = setTimeout(function () { espera = null; refrescar(); }, ESPERA_MS);
    }

    function conectar(el) {
        if (typeof mqtt === 'undefined') return;

        var topic = el.dataset.spuiMqttTopic;
        var cliente = mqtt.connect(el.dataset.spuiMqttUrl, {
            username: el.dataset.spuiMqttUsuario,
            password: el.dataset.spuiMqttPassword,
            // Sufijo aleatorio: dos pestañas con el mismo id se echarían entre
            // sí del broker.
            clientId: 'spui-web-cx-' + Math.random().toString(16).slice(2, 10),
            clean: true,
            connectTimeout: 5000,
            reconnectPeriod: 5000
        });

        var yaConecto = false;
        cliente.on('connect', function () {
            cliente.subscribe(topic);
            // Si el socket se cortó y volvió, pudo haberse perdido un aviso en
            // el medio (se publican sin retain): una vuelta a pedir la tabla
            // lo cubre. La primera conexión no lo necesita, la página es nueva.
            if (yaConecto) programar();
            yaConecto = true;
        });

        cliente.on('message', function () { programar(); });

        // Sin manejador, mqtt.js tira cada reintento como excepción no
        // capturada. Si el broker no está, la página funciona igual: sólo deja
        // de actualizarse sola.
        cliente.on('error', function () {});

        // Cerrar prolijo al irse de la página, para no dejar sesiones colgadas
        // en el broker hasta que venza el keepalive.
        window.addEventListener('pagehide', function () {
            try { cliente.end(true); } catch (e) { /* ya estaba cerrado */ }
        });
    }

    document.addEventListener('spui:modal:cerrado', function () {
        if (pendiente) programar();
    });

    // Si la tabla ya se refrescó por otro camino (al volver a la pestaña), el
    // aviso postergado quedó cubierto.
    document.addEventListener('spui:refrescado', function () { pendiente = false; });

    document.addEventListener('DOMContentLoaded', function () {
        var el = document.querySelector('[data-spui-conexion-vivo]');
        if (el && document.querySelector('[data-spui-refresh="push"]')) conectar(el);
    });
}());
