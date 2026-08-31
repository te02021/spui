// Visor de media a pantalla completa: cualquier ícono con
// data-spui-preview-url lo abre, mostrando imagen o video según
// data-spui-preview-tipo. Un solo overlay reusado para todo el CMS —
// Contenido hoy, Playlist más adelante puede sumarse con el mismo atributo,
// sin tocar este archivo.
//
// Se agrega a document.body (no donde esté el trigger en el DOM) para que
// su z-index compare directamente contra el de los modales ABM sin
// depender de en qué contenedor haya quedado montado.
(function () {
    'use strict';

    window.spui = window.spui || {};

    // Pasos fijos de 10 puntos porcentuales, no multiplicativos: de
    // multiplicar por 1.2 cada vez salían porcentajes como 144%, 172.8%...
    // en vez de números redondos. El mínimo va por debajo de 100 a
    // propósito -- estaba clavado en el 100% exacto y esa era la "flecha
    // para abajo" que no hacía nada.
    var ZOOM_PASO_PCT = 10;
    var ZOOM_MIN_PCT  = 50;
    var ZOOM_MAX_PCT  = 400;

    var overlay = null;
    var contenido = null;
    var nombreEl = null;
    var zoomPctEl = null;
    var btnDescarga = null;
    var el = null;         // <img> o <video> actualmente mostrado
    var porcentaje = 100;  // lo que se ve en pantalla y lo que se pasa a escala
    var urlActual = null;
    // El video ya trae sus propios controles nativos (incluida su barra de
    // progreso y pantalla completa) y escalarlo con transform no aporta nada
    // -- el zoom queda sólo para imágenes.
    var zoomHabilitado = true;

    function crearOverlay() {
        overlay = document.createElement('div');
        overlay.className = 'spui-visor-overlay';
        overlay.innerHTML =
            '<div class="spui-visor-header">' +
                '<span class="spui-visor-nombre"></span>' +
                '<div class="spui-visor-herramientas">' +
                    '<button type="button" class="unraf-btn spui-btn-back unraf-btn-icon" data-spui-visor-zoom-out title="Alejar">' +
                        '<img alt="Alejar">' +
                    '</button>' +
                    '<span class="spui-visor-zoom-pct">100%</span>' +
                    '<button type="button" class="unraf-btn spui-btn-back unraf-btn-icon" data-spui-visor-zoom-in title="Acercar">' +
                        '<img alt="Acercar">' +
                    '</button>' +
                    '<a class="unraf-btn spui-btn-ver unraf-btn-icon" data-spui-visor-descarga download title="Descargar">' +
                        '<img alt="Descargar">' +
                    '</a>' +
                    '<button type="button" class="unraf-btn spui-btn-eliminar unraf-btn-icon" data-spui-visor-cerrar title="Cerrar vista previa">' +
                        '<img alt="Cerrar">' +
                    '</button>' +
                '</div>' +
            '</div>' +
            '<div class="spui-visor-viewport"><div class="spui-visor-contenido"></div></div>';

        document.body.appendChild(overlay);

        contenido   = overlay.querySelector('.spui-visor-contenido');
        nombreEl    = overlay.querySelector('.spui-visor-nombre');
        zoomPctEl   = overlay.querySelector('.spui-visor-zoom-pct');
        btnDescarga = overlay.querySelector('[data-spui-visor-descarga]');

        overlay.querySelector('[data-spui-visor-zoom-in]  img').src = window.spui.iconos.magnifyingGlassPlus;
        overlay.querySelector('[data-spui-visor-zoom-out] img').src = window.spui.iconos.magnifyingGlassMinus;
        overlay.querySelector('[data-spui-visor-descarga] img').src = window.spui.iconos.download;
        overlay.querySelector('[data-spui-visor-cerrar]   img').src = window.spui.iconos.x;

        // Fondo (fuera del header/viewport) cierra, igual que los modales del CMS.
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) cerrar();
        });
        overlay.querySelector('[data-spui-visor-cerrar]').addEventListener('click', cerrar);
        overlay.querySelector('[data-spui-visor-zoom-in]').addEventListener('click', function () { zoom(1, null); });
        overlay.querySelector('[data-spui-visor-zoom-out]').addEventListener('click', function () { zoom(-1, null); });

        overlay.querySelector('.spui-visor-viewport').addEventListener('wheel', function (e) {
            if (!el || !zoomHabilitado) return;
            e.preventDefault();
            zoom(e.deltaY < 0 ? 1 : -1, e);
        }, { passive: false });
    }

    function porEscape(e) {
        if (e.key === 'Escape') cerrar();
    }

    function resetZoom() {
        porcentaje = 100;
        if (el) {
            el.style.transform = '';
            el.style.transformOrigin = '';
            el.classList.remove('is-zoomed');
        }
        if (zoomPctEl) zoomPctEl.textContent = '100%';
    }

    /**
     * Acerca/aleja de a ZOOM_PASO_PCT puntos porcentuales por vez, entre
     * ZOOM_MIN_PCT y ZOOM_MAX_PCT (100% no es ni el piso ni el techo).
     *
     * direccion es +1 (acercar) o -1 (alejar) — no un factor multiplicativo,
     * para que el porcentaje mostrado sea siempre un número redondo
     * (100, 110, 120…), no 120, 144, 172.8…
     *
     * Sin evento de mouse (botones) centra en el medio del elemento; con
     * wheel, recalcula el origen de la transformación al punto donde está
     * el cursor en cada tick — el punto bajo el cursor queda fijo en
     * pantalla mientras el resto de la imagen crece/decrece a su
     * alrededor, que es el efecto de "zoom hacia el cursor" esperado.
     */
    function zoom(direccion, evento) {
        if (!el || !zoomHabilitado) return;

        var nuevo = Math.min(ZOOM_MAX_PCT, Math.max(ZOOM_MIN_PCT, porcentaje + direccion * ZOOM_PASO_PCT));
        if (nuevo === porcentaje) return;
        porcentaje = nuevo;

        if (evento) {
            var rect = el.getBoundingClientRect();
            var xPct = ((evento.clientX - rect.left) / rect.width) * 100;
            var yPct = ((evento.clientY - rect.top) / rect.height) * 100;
            el.style.transformOrigin = xPct + '% ' + yPct + '%';
        } else {
            el.style.transformOrigin = '50% 50%';
        }

        var escala = porcentaje / 100;
        el.style.transform = 'scale(' + escala + ')';
        el.classList.toggle('is-zoomed', escala > 1);
        if (zoomPctEl) zoomPctEl.textContent = porcentaje + '%';
    }

    function cerrar() {
        if (!overlay || !overlay.classList.contains('is-open')) return;
        // Vaciar el contenedor corta la reproducción del video en vez de
        // dejarlo sonando de fondo con el visor cerrado.
        contenido.innerHTML = '';
        el = null;
        overlay.classList.remove('is-open');
        document.removeEventListener('keydown', porEscape);
    }

    /**
     * Abre el visor con la URL dada. tipo 'video' arma un <video controls>
     * (controles nativos del navegador: play/pausa, volumen, barra de
     * progreso, pantalla completa propia); cualquier otro tipo se muestra
     * como imagen con el zoom propio de este visor.
     */
    function abrir(url, tipo, nombre) {
        if (!overlay) crearOverlay();
        contenido.innerHTML = '';
        urlActual = url;
        zoomHabilitado = tipo !== 'video';
        overlay.classList.toggle('spui-visor-sin-zoom', !zoomHabilitado);

        if (tipo === 'video') {
            el = document.createElement('video');
            el.controls = true;
            el.autoplay = true;
            el.src = url;
        } else {
            el = document.createElement('img');
            el.alt = nombre || 'Vista previa';
            el.src = url;
        }
        contenido.appendChild(el);
        resetZoom();

        nombreEl.textContent = nombre || '';
        btnDescarga.href = url;
        btnDescarga.download = nombre || '';

        overlay.classList.add('is-open');
        document.addEventListener('keydown', porEscape);
    }

    window.spui.abrirVisorMedia = abrir;

    // Delegado en document: funciona también con íconos que llegan dentro de
    // HTML inyectado por los modales AJAX, sin tener que re-bindear nada.
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-spui-preview-url]');
        if (!trigger) return;
        e.preventDefault();
        abrir(
            trigger.getAttribute('data-spui-preview-url'),
            trigger.getAttribute('data-spui-preview-tipo') || 'imagen',
            trigger.getAttribute('data-spui-preview-nombre') || ''
        );
    });
}());
