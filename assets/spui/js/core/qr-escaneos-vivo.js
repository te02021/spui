// Contador de escaneos de un código QR, en vivo.
//
// El CMS publica en spui/qr/escaneo/{id} en el mismo momento en que alguien
// escanea (QrEscaneoPublisherService); acá el navegador queda suscrito a ese
// topic por WebSocket y actualiza el número solo. No hay ningún timer ni
// ninguna consulta periódica: entre escaneo y escaneo esta página no manda un
// solo byte, que es justo el punto — un QR puede no escanearse en todo el día.
//
// La conexión se abre SÓLO mientras el modal Ver de un contenido QR está
// abierto, y se cierra al cerrarlo: ninguna pestaña queda con un socket vivo
// dando vueltas. Las credenciales llegan en los data-* del propio fragmento
// (ver contenidos/_view.html.twig) y son de sólo lectura sobre spui/qr/#.
(function () {
    'use strict';

    var SELECTOR = '[data-spui-qr-escaneos][data-spui-mqtt-topic]';

    var cliente     = null;
    var topicActual = null;

    function desconectar() {
        if (!cliente) return;
        // force=true: no esperar el DISCONNECT limpio. El modal ya se cerró y
        // no hay nada que confirmar.
        try { cliente.end(true); } catch (e) { /* ya estaba cerrado */ }
        cliente     = null;
        topicActual = null;
    }

    // Deja el número nuevo y lo resalta un momento, para que el cambio se note
    // aunque nadie estuviera mirando esa fila exacta.
    function pintar(valor) {
        var el = document.querySelector('[data-spui-qr-escaneos]');
        if (!el || el.textContent.trim() === String(valor)) return;

        el.textContent = valor;
        el.style.transition = 'color .2s ease';
        el.style.color = 'var(--unraf-turquesa, #1CA1A7)';
        el.style.fontWeight = '600';
        setTimeout(function () {
            el.style.color = '';
            el.style.fontWeight = '';
        }, 900);
    }

    function conectar(el) {
        var topic = el.dataset.spuiMqttTopic;
        var url   = el.dataset.spuiMqttUrl;
        if (!topic || !url) return;

        cliente = mqtt.connect(url, {
            username: el.dataset.spuiMqttUsuario,
            password: el.dataset.spuiMqttPassword,
            // Sufijo aleatorio: dos pestañas del CMS con el mismo id se
            // desconectarían entre sí.
            clientId: 'spui-web-' + Math.random().toString(16).slice(2, 10),
            clean: true,
            connectTimeout: 5000,
            reconnectPeriod: 5000
        });
        topicActual = topic;

        cliente.on('connect', function () { cliente.subscribe(topic); });

        cliente.on('message', function (t, payload) {
            if (t !== topic) return;
            try {
                var datos = JSON.parse(payload.toString());
                if (typeof datos.usos === 'number') pintar(datos.usos);
            } catch (e) { /* payload ajeno o cortado: se ignora */ }
        });

        // Sin manejador, mqtt.js emite el error como excepción no capturada y
        // ensucia la consola en cada reintento con el broker caído. Que el
        // contador no se mueva ya es toda la señal que necesita el operador.
        cliente.on('error', function () {});
    }

    // El modal reemplaza el contenido de #spuiAbmBody al abrir y lo vacía al
    // cerrar: observar ese nodo cubre los dos casos con una sola regla, sin
    // depender de qué eventos dispare modal.js.
    function sincronizar() {
        var el = document.querySelector(SELECTOR);

        if (!el) { desconectar(); return; }
        if (cliente && el.dataset.spuiMqttTopic === topicActual) return;

        desconectar();
        if (typeof mqtt !== 'undefined') conectar(el);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var cuerpo = document.getElementById('spuiAbmBody');
        if (!cuerpo) return;

        new MutationObserver(sincronizar).observe(cuerpo, { childList: true, subtree: true });
        sincronizar();
    });

    // Cerrar la pestaña con el modal abierto dejaba al broker esperando el
    // keepalive del cliente hasta que venciera.
    window.addEventListener('pagehide', desconectar);
})();
