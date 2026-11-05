// Visor a pantalla completa (rediseñado el 02/11/2026). Dos pestañas:
//
//   Archivo     — la imagen, el video o el QR tal cual se subió, con zoom
//                 (sólo imágenes: el video trae sus propios controles).
//   En pantalla — cómo se ve en el TV, con su plantilla y su tema
//                 (pantalla/_tv.html.twig, lo anima core/vista-tv.js).
//
// Se abre con cualquier elemento que tenga:
//   data-spui-preview-url="…"            el archivo (+ data-spui-preview-tipo,
//                                        data-spui-preview-nombre)
//   data-spui-preview-pantalla-url="…"   (opcional) la vista en el TV
// o con data-spui-ver-pantalla="…" para abrir sólo la vista en el TV. Desde
// JS: window.spui.abrirVisorMedia(url, tipo, nombre, opciones) y
// window.spui.abrirVisorPantalla(html, titulo).
//
// Se agrega a document.body (no donde esté el trigger) para que su z-index
// compare directo contra el de los modales ABM.
(function () {
    'use strict';

    window.spui = window.spui || {};

    // Pasos fijos de 10 puntos porcentuales, no multiplicativos: de
    // multiplicar por 1.2 cada vez salían porcentajes como 144%, 172.8%...
    var ZOOM_PASO_PCT = 10;
    var ZOOM_MIN_PCT  = 50;
    var ZOOM_MAX_PCT  = 400;

    var ETIQUETAS = { imagen: 'Imagen', video: 'Video', qr: 'Código QR', texto: 'Texto', cronograma: 'Cronograma' };

    var overlay, zonas, nombreEl, metaEl, zoomPctEl, btnDescarga, pestanas;
    var el = null;          // <img> o <video> de la pestaña Archivo
    var porcentaje = 100;
    var estado = {};        // lo que se abrió: url, tipo, pantalla…

    function icono(nombre) { return (window.spui.iconos && window.spui.iconos[nombre]) || ''; }

    function crearOverlay() {
        overlay = document.createElement('div');
        overlay.className = 'spui-visor-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Vista previa');
        overlay.innerHTML =
            '<div class="spui-visor-header">' +
                '<div class="spui-visor-titulo">' +
                    '<span class="spui-visor-nombre"></span>' +
                    '<span class="spui-visor-meta"></span>' +
                '</div>' +
                '<div class="spui-visor-pestanas" role="tablist" aria-label="Qué ver">' +
                    '<button type="button" role="tab" data-spui-visor-pestana="archivo">Archivo</button>' +
                    '<button type="button" role="tab" data-spui-visor-pestana="pantalla">En pantalla</button>' +
                '</div>' +
                '<div class="spui-visor-herramientas">' +
                    '<span class="spui-visor-zoom" data-spui-visor-solo-zoom>' +
                        '<button type="button" class="spui-visor-btn" data-spui-visor-zoom-out title="Alejar" aria-label="Alejar"><img alt=""></button>' +
                        '<button type="button" class="spui-visor-zoom-pct" data-spui-visor-zoom-reset title="Volver al tamaño de la ventana">100%</button>' +
                        '<button type="button" class="spui-visor-btn" data-spui-visor-zoom-in title="Acercar" aria-label="Acercar"><img alt=""></button>' +
                    '</span>' +
                    '<a class="spui-visor-btn" data-spui-visor-descarga download title="Descargar el archivo" aria-label="Descargar el archivo"><img alt=""></a>' +
                    '<button type="button" class="spui-visor-btn spui-visor-cerrar" data-spui-visor-cerrar title="Cerrar (Esc)" aria-label="Cerrar"><img alt=""></button>' +
                '</div>' +
            '</div>' +
            '<div class="spui-visor-viewport" data-spui-visor-zona="archivo" role="tabpanel">' +
                '<div class="spui-visor-contenido"></div>' +
                '<div class="spui-visor-estado" data-spui-visor-cargando><span class="spui-visor-spinner" aria-hidden="true"></span>Cargando…</div>' +
                '<div class="spui-visor-estado" data-spui-visor-error hidden>No se pudo cargar el archivo. Probá de nuevo en un momento.</div>' +
            '</div>' +
            '<div class="spui-visor-viewport spui-visor-pantalla" data-spui-visor-zona="pantalla" role="tabpanel" hidden>' +
                '<div class="spui-visor-tv"></div>' +
            '</div>';

        document.body.appendChild(overlay);

        zonas = {
            archivo: overlay.querySelector('[data-spui-visor-zona="archivo"]'),
            pantalla: overlay.querySelector('[data-spui-visor-zona="pantalla"]'),
        };
        nombreEl    = overlay.querySelector('.spui-visor-nombre');
        metaEl      = overlay.querySelector('.spui-visor-meta');
        zoomPctEl   = overlay.querySelector('.spui-visor-zoom-pct');
        btnDescarga = overlay.querySelector('[data-spui-visor-descarga]');
        pestanas    = overlay.querySelectorAll('[data-spui-visor-pestana]');

        overlay.querySelector('[data-spui-visor-zoom-in] img').src  = icono('magnifyingGlassPlus');
        overlay.querySelector('[data-spui-visor-zoom-out] img').src = icono('magnifyingGlassMinus');
        overlay.querySelector('[data-spui-visor-descarga] img').src = icono('download');
        overlay.querySelector('[data-spui-visor-cerrar] img').src   = icono('x');

        // Fondo (fuera del contenido) cierra, igual que los modales del CMS.
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay || e.target.classList.contains('spui-visor-viewport')) cerrar();
        });
        overlay.querySelector('[data-spui-visor-cerrar]').addEventListener('click', cerrar);
        overlay.querySelector('[data-spui-visor-zoom-in]').addEventListener('click', function () { zoom(1, null); });
        overlay.querySelector('[data-spui-visor-zoom-out]').addEventListener('click', function () { zoom(-1, null); });
        overlay.querySelector('[data-spui-visor-zoom-reset]').addEventListener('click', resetZoom);
        pestanas.forEach(function (b) {
            b.addEventListener('click', function () { mostrar(b.getAttribute('data-spui-visor-pestana')); });
        });
        zonas.archivo.addEventListener('wheel', function (e) {
            if (!el || estado.tipo === 'video') return;
            e.preventDefault();
            zoom(e.deltaY < 0 ? 1 : -1, e);
        }, { passive: false });
    }

    function porTeclado(e) {
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
     * Acerca/aleja de a ZOOM_PASO_PCT puntos. Con la rueda del mouse, hacia
     * el punto donde está el cursor (queda fijo mientras el resto crece).
     */
    function zoom(direccion, evento) {
        if (!el || estado.tipo === 'video') return;
        var nuevo = Math.min(ZOOM_MAX_PCT, Math.max(ZOOM_MIN_PCT, porcentaje + direccion * ZOOM_PASO_PCT));
        if (nuevo === porcentaje) return;
        porcentaje = nuevo;
        if (evento) {
            var r = el.getBoundingClientRect();
            el.style.transformOrigin = ((evento.clientX - r.left) / r.width * 100) + '% ' + ((evento.clientY - r.top) / r.height * 100) + '%';
        } else {
            el.style.transformOrigin = '50% 50%';
        }
        el.style.transform = 'scale(' + (porcentaje / 100) + ')';
        el.classList.toggle('is-zoomed', porcentaje > 100);
        zoomPctEl.textContent = porcentaje + '%';
    }

    function cerrar() {
        if (!overlay || !overlay.classList.contains('is-open')) return;
        // Vaciar corta el video (y la animación del TV) en vez de dejarlos
        // andando con el visor cerrado.
        overlay.querySelector('.spui-visor-contenido').innerHTML = '';
        overlay.querySelector('.spui-visor-tv').innerHTML = '';
        el = null;
        overlay.classList.remove('is-open');
        document.removeEventListener('keydown', porTeclado);
        if (estado.volverA && estado.volverA.focus) estado.volverA.focus();
    }

    function mostrar(cual) {
        estado.pestana = cual;
        // El video de la pestaña que se deja no sigue sonando de fondo. El de En
        // pantalla no tiene controles: al volver a esa pestaña sigue solo.
        Object.keys(zonas).forEach(function (z) {
            if (z !== cual) zonas[z].querySelectorAll('video').forEach(function (v) { v.pause(); });
        });
        if (cual === 'pantalla') {
            zonas.pantalla.querySelectorAll('video').forEach(function (v) {
                var reproduccion = v.play();
                if (reproduccion && reproduccion.catch) reproduccion.catch(function () {});
            });
        }
        zonas.archivo.hidden = cual !== 'archivo';
        zonas.pantalla.hidden = cual !== 'pantalla';
        pestanas.forEach(function (b) {
            var on = b.getAttribute('data-spui-visor-pestana') === cual;
            b.classList.toggle('is-activa', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        // Zoom y descarga son del archivo.
        overlay.querySelector('[data-spui-visor-solo-zoom]').hidden = cual !== 'archivo' || estado.tipo === 'video';
        btnDescarga.hidden = cual !== 'archivo';
        actualizarMeta();
        if (cual === 'pantalla') cargarPantalla();
    }

    function actualizarMeta() {
        var partes = [ETIQUETAS[estado.tipo] || ''];
        if (estado.pestana === 'archivo' && estado.dimension) partes.push(estado.dimension);
        if (estado.pestana === 'pantalla') partes.push('Vista en el TV');
        metaEl.textContent = partes.filter(Boolean).join(' · ');
    }

    function duracion(seg) {
        seg = Math.round(seg);
        return Math.floor(seg / 60) + ':' + ('0' + (seg % 60)).slice(-2);
    }

    function cargarArchivo() {
        var cont = overlay.querySelector('.spui-visor-contenido');
        var cargando = overlay.querySelector('[data-spui-visor-cargando]');
        var error = overlay.querySelector('[data-spui-visor-error]');
        cont.innerHTML = '';
        error.hidden = true;
        cargando.hidden = false;
        estado.dimension = '';

        if (estado.tipo === 'video') {
            el = document.createElement('video');
            el.controls = true;
            el.autoplay = true;
            el.addEventListener('loadedmetadata', function () {
                estado.dimension = el.videoWidth + ' × ' + el.videoHeight + ' · ' + duracion(el.duration);
                cargando.hidden = true;
                actualizarMeta();
            });
        } else {
            el = document.createElement('img');
            el.alt = estado.nombre || 'Vista previa';
            el.className = 'spui-visor-cuadros';
            el.addEventListener('load', function () {
                estado.dimension = el.naturalWidth + ' × ' + el.naturalHeight + ' px';
                cargando.hidden = true;
                actualizarMeta();
            });
        }
        el.addEventListener('error', function () {
            cargando.hidden = true;
            error.hidden = false;
            el.hidden = true;
        });
        el.src = estado.url;
        cont.appendChild(el);
        resetZoom();
        btnDescarga.href = estado.url;
        btnDescarga.download = estado.nombre || '';
    }

    function cargarPantalla() {
        var tv = overlay.querySelector('.spui-visor-tv');
        if (estado.pantallaLista) return;
        estado.pantallaLista = true;
        if (estado.pantallaHtml) {
            tv.innerHTML = estado.pantallaHtml;
            window.spui.vistaTv && window.spui.vistaTv.iniciar(tv);
            return;
        }
        tv.innerHTML = '<div class="spui-visor-estado"><span class="spui-visor-spinner" aria-hidden="true"></span>Armando la vista…</div>';
        fetch(estado.pantallaUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
            .then(function (d) {
                // Sólo el TV: la leyenda y los botones son de la vista chica.
                var tmp = document.createElement('div');
                tmp.innerHTML = d.html || '';
                var escala = tmp.querySelector('.spui-vista-previa-tv');
                tv.innerHTML = escala ? escala.innerHTML : '';
                window.spui.vistaTv && window.spui.vistaTv.iniciar(tv);
            })
            .catch(function () {
                tv.innerHTML = '<div class="spui-visor-estado">No se pudo armar la vista. Probá de nuevo en un momento.</div>';
            });
    }

    /**
     * url: el archivo (null si sólo hay vista en el TV). opciones:
     * pantallaUrl (GET que devuelve {html}) o pantallaHtml, y pestana
     * ('archivo' | 'pantalla') para elegir con cuál abre.
     */
    function abrir(url, tipo, nombre, opciones) {
        opciones = opciones || {};
        if (!overlay) crearOverlay();
        estado = {
            url: url, tipo: tipo, nombre: nombre,
            pantallaUrl: opciones.pantallaUrl || null,
            pantallaHtml: opciones.pantallaHtml || null,
            volverA: document.activeElement,
        };
        var hayPantalla = !!(estado.pantallaUrl || estado.pantallaHtml);
        overlay.querySelector('.spui-visor-tv').innerHTML = '';
        overlay.querySelector('.spui-visor-pestanas').hidden = !(url && hayPantalla);
        pestanas[0].hidden = !url;
        pestanas[1].hidden = !hayPantalla;
        nombreEl.textContent = nombre || '';

        if (url) cargarArchivo();
        overlay.classList.add('is-open');
        document.addEventListener('keydown', porTeclado);
        mostrar(url && opciones.pestana !== 'pantalla' ? 'archivo' : 'pantalla');
        overlay.querySelector('[data-spui-visor-cerrar]').focus();
    }

    window.spui.abrirVisorMedia = abrir;
    window.spui.abrirVisorPantalla = function (html, titulo) {
        abrir(null, null, titulo, { pantallaHtml: html, pestana: 'pantalla' });
    };

    // Delegado en document: funciona también con íconos que llegan dentro de
    // HTML inyectado por los modales AJAX, sin tener que re-bindear nada.
    document.addEventListener('click', function (e) {
        var t = e.target.closest && e.target.closest('[data-spui-preview-url], [data-spui-ver-pantalla]');
        if (!t) return;
        e.preventDefault();
        var url = t.getAttribute('data-spui-preview-url');
        abrir(
            url,
            t.getAttribute('data-spui-preview-tipo') || (url ? 'imagen' : null),
            t.getAttribute('data-spui-preview-nombre') || '',
            {
                pantallaUrl: t.getAttribute('data-spui-preview-pantalla-url') || t.getAttribute('data-spui-ver-pantalla'),
                pestana: t.hasAttribute('data-spui-ver-pantalla') ? 'pantalla' : 'archivo',
            }
        );
    });
}());
