// Vista previa del TV (noviembre 2026): templates/pantalla/_tv.html.twig.
//
//   - Escala: el TV se dibuja a 1920 × 1080 y se achica al ancho disponible.
//   - Texto: se achica la letra hasta que entra todo, como hace la Pi.
//   - Cronograma: las filas se reparten en páginas por altura (las que
//     entran); cada página queda sus segundos y se desliza a la izquierda
//     mientras entra la siguiente desde la derecha (elección de Mateo).
//   - Carga: los [data-spui-vista-previa-url] se llenan solos. Dentro de un
//     formulario se manda lo que se está cargando (POST) y se vuelve a pedir
//     a medida que cambia; en el modal Ver, el contenido guardado (GET).
//   - Ampliar: abre la misma vista en el visor a pantalla completa.
//   - Hora: los [data-spui-tv-hora] se mantienen al día, como en la Pi.
(function () {
    'use strict';

    var ANCHO = 1920;
    var DESLIZAR_MS = 1400;
    var CURVA = 'cubic-bezier(.65, 0, .35, 1)';
    var ESPERA_MS = 450;   // tras el último cambio del formulario, antes de pedir

    var escalador = typeof ResizeObserver !== 'undefined'
        ? new ResizeObserver(function (es) { es.forEach(function (e) { escalar(e.target); }); })
        : null;

    function escalar(escala) {
        var tv = escala.querySelector('.spui-tv');
        if (!tv || !escala.clientWidth) return;
        tv.style.transform = 'scale(' + (escala.clientWidth / ANCHO) + ')';
        // Texto y páginas se arman recién cuando la vista se ve: oculta mide 0
        // de alto (la letra bajaría al mínimo y no entraría ninguna fila).
        if (!escala.dataset.spuiTvAjustado) {
            escala.dataset.spuiTvAjustado = '1';
            escala.querySelectorAll('[data-spui-tv-ajustar]').forEach(ajustarTexto);
            paginar(escala);
        }
    }

    // Mismo criterio que la Pi: la letra baja de a poco hasta que entra.
    function ajustarTexto(el) {
        var caja = el.closest('.tv-texto, .tv-qr-txt') || el.parentElement;
        var tam = parseFloat(getComputedStyle(el).fontSize);
        var limite = el.closest('.tv-qr-txt') ? 640 : caja.clientHeight;
        while (tam > 28 && el.scrollHeight > limite) {
            tam -= 4;
            el.style.fontSize = tam + 'px';
        }
    }

    // Reparte las filas del cronograma en páginas según lo que ocupa cada
    // una: una materia larga baja a otro renglón (nunca se corta), así que no
    // entra siempre la misma cantidad. La lista original queda oculta, como
    // molde, y las páginas son copias.
    function armarPaginas(escala) {
        var origen = escala.querySelector('[data-spui-tv-origen]');
        if (!origen) return [];
        var alto = origen.parentElement.clientHeight;
        var hueco = parseFloat(getComputedStyle(origen).rowGap) || 0;
        var paginas = [[]], usado = 0;
        Array.prototype.forEach.call(origen.children, function (fila) {
            var h = fila.offsetHeight;
            var pagina = paginas[paginas.length - 1];
            if (pagina.length && usado + hueco + h > alto) {
                paginas.push([]);
                pagina = paginas[paginas.length - 1];
                usado = 0;
            }
            usado += (pagina.length ? hueco : 0) + h;
            pagina.push(fila);
        });
        if (paginas.length < 2) return [];

        origen.hidden = true;
        return paginas.map(function (filas, i) {
            var p = document.createElement('div');
            p.className = 'tv-pagina';
            p.setAttribute('data-spui-tv-pagina', '');
            p.hidden = i > 0;
            filas.forEach(function (f) { p.appendChild(f.cloneNode(true)); });
            origen.parentElement.appendChild(p);
            return p;
        });
    }

    function paginar(escala) {
        var seg = parseInt(escala.getAttribute('data-spui-tv-segundos'), 10);
        if (!seg) return;
        var paginas = armarPaginas(escala);
        if (paginas.length < 2) return;
        var avance = escala.querySelector('[data-spui-tv-avance]');
        if (avance) {
            avance.innerHTML = paginas.map(function (p, i) { return '<i class="' + (i ? '' : 'on') + '"></i>'; }).join('') +
                '<em data-spui-tv-numero>1 / ' + paginas.length + '</em>';
            avance.hidden = false;
        }
        var puntos = escala.querySelectorAll('.tv-avance i');
        var numero = escala.querySelector('[data-spui-tv-numero]');
        var actual = 0;

        function siguiente() {
            // Se corta solo cuando la vista ya no está (se cerró el modal o
            // se volvió a pedir).
            if (!escala.isConnected) return;
            var sale = paginas[actual];
            actual = (actual + 1) % paginas.length;
            var entra = paginas[actual];

            entra.style.transition = 'none';
            entra.style.transform = 'translateX(105%)';
            entra.hidden = false;
            entra.offsetWidth;   // que tome la posición de partida antes de animar
            var tr = 'transform ' + DESLIZAR_MS + 'ms ' + CURVA;
            sale.style.transition = tr;
            entra.style.transition = tr;
            sale.style.transform = 'translateX(-105%)';
            entra.style.transform = 'translateX(0)';
            puntos.forEach(function (p, i) { p.classList.toggle('on', i === actual); });
            if (numero) numero.textContent = (actual + 1) + ' / ' + paginas.length;

            setTimeout(function () {
                sale.hidden = true;
                sale.style.transform = '';
                setTimeout(siguiente, seg * 1000);
            }, DESLIZAR_MS + 50);
        }
        setTimeout(siguiente, seg * 1000);
    }

    // La hora de todas las vistas que haya en la página, cuando cambia el minuto.
    function actualizarHoras() {
        var d = new Date();
        var hhmm = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
        document.querySelectorAll('[data-spui-tv-hora]').forEach(function (el) {
            if (el.textContent !== hhmm) el.textContent = hhmm;
        });
    }
    setInterval(actualizarHoras, 15000);

    /** Pone en marcha los TV que haya dentro de root (uno recién pintado). */
    function iniciar(root) {
        (root || document).querySelectorAll('[data-spui-tv]').forEach(function (escala) {
            if (escala.dataset.spuiTvListo) return;
            escala.dataset.spuiTvListo = '1';
            escalar(escala);
            if (escalador) escalador.observe(escala);
        });
    }

    // ── Carga desde el servidor ─────────────────────────────────────────────

    function pedir(cont) {
        var url = cont.getAttribute('data-spui-vista-previa-url');
        var form = cont.closest('form');
        var opciones = { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' };
        var edificio = cont.querySelector('[data-spui-tv-edificio]');

        if (form) {
            var datos = new FormData(form);
            datos.delete('archivo');   // el archivo lo muestra el navegador, no se sube
            if (cont.dataset.spuiVistaId) datos.set('vista_id', cont.dataset.spuiVistaId);
            if (edificio) datos.set('vista_edificio', edificio.value);
            opciones.method = 'POST';
            opciones.body = datos;
        } else if (edificio) {
            url += (url.indexOf('?') === -1 ? '?' : '&') + 'edificio=' + encodeURIComponent(edificio.value);
        }

        var pedido = (cont._spuiPedido || 0) + 1;
        cont._spuiPedido = pedido;
        cont.classList.add('is-cargando');
        fetch(url, opciones)
            .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
            .then(function (d) {
                if (cont._spuiPedido !== pedido) return;   // llegó tarde: hay uno más nuevo
                var antes = cont.hidden ? 0 : cont.offsetHeight;
                cont.innerHTML = d.html || '';
                cont.hidden = !d.html;
                if (form) archivoLocal(form, cont);
                iniciar(cont);
                crecerSuave(cont, antes);
            })
            .catch(function () {
                if (cont._spuiPedido !== pedido) return;
                cont.innerHTML = '<p class="spui-tv-leyenda">No se pudo armar la vista previa. Probá de nuevo en un momento.</p>';
            })
            .then(function () { cont.classList.remove('is-cargando'); });
    }

    // Cuando la vista aparece o cambia de alto (otro tipo de contenido), la
    // caja pasa del alto anterior al nuevo de a poco, en vez de saltar.
    function crecerSuave(cont, antes) {
        var despues = cont.offsetHeight;
        if (!cont.animate || Math.abs(despues - antes) < 4
            || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) return;
        cont.style.overflow = 'hidden';
        cont.animate(
            [{ height: antes + 'px', opacity: antes ? 1 : 0 }, { height: despues + 'px', opacity: 1 }],
            { duration: 480, easing: 'cubic-bezier(.22, .61, .36, 1)' }
        ).onfinish = function () { cont.style.overflow = ''; };
    }

    // Un archivo recién elegido todavía no está en el servidor: se muestra
    // desde el navegador.
    function archivoLocal(form, cont) {
        var input = form.querySelector('input[type="file"][name="archivo"]');
        var archivo = input && input.files && input.files[0];
        if (!archivo) return;
        var esVideo = archivo.type.indexOf('video/') === 0;
        if (!esVideo && archivo.type.indexOf('image/') !== 0) return;
        if (cont._spuiObjeto) URL.revokeObjectURL(cont._spuiObjeto);
        cont._spuiObjeto = URL.createObjectURL(archivo);
        var hueco = cont.querySelector('[data-spui-tv-media], [data-spui-tv-media-falta]');
        if (!hueco) return;
        var el = document.createElement(esVideo ? 'video' : 'img');
        el.className = 'tv-media';
        el.setAttribute('data-spui-tv-media', '');
        if (esVideo) { el.muted = true; el.autoplay = true; el.loop = true; el.playsInline = true; } else { el.alt = ''; }
        el.src = cont._spuiObjeto;
        hueco.replaceWith(el);
    }

    function cargarTodas(root) {
        (root || document).querySelectorAll('[data-spui-vista-previa-url]').forEach(function (cont) {
            if (cont.dataset.spuiVistaLista) return;
            cont.dataset.spuiVistaLista = '1';
            pedir(cont);
        });
    }

    var esperas = new WeakMap();
    function alCambiarFormulario(e) {
        var form = e.target && e.target.form;
        if (!form) return;
        var cont = form.querySelector('[data-spui-vista-previa-url]');
        if (!cont) return;
        if (e.target.matches('[data-spui-tv-edificio]')) { pedir(cont); return; }
        clearTimeout(esperas.get(cont));
        esperas.set(cont, setTimeout(function () { pedir(cont); }, e.type === 'input' ? ESPERA_MS : 0));
    }

    document.addEventListener('DOMContentLoaded', function () { cargarTodas(); iniciar(); });
    document.addEventListener('spui:modal:loaded', function (e) { cargarTodas(e.target); });
    document.addEventListener('change', alCambiarFormulario);
    document.addEventListener('input', function (e) {
        // Lo que se escribe (título, texto) espera a que se deje de tipear.
        if (e.target && e.target.matches && e.target.matches('input[type="text"], textarea')) alCambiarFormulario(e);
    });
    // El selector de edificio de la vista del modal Ver (fuera de un form).
    document.addEventListener('change', function (e) {
        if (!e.target.matches || !e.target.matches('[data-spui-tv-edificio]') || e.target.form) return;
        var cont = e.target.closest('[data-spui-vista-previa-url]');
        if (cont) pedir(cont);
    });

    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-spui-tv-ampliar]');
        if (!btn) return;
        var cont = btn.closest('[data-spui-vista-previa-url]');
        var tv = cont && cont.querySelector('.spui-vista-previa-tv');
        if (!tv || !window.spui.abrirVisorPantalla) return;
        window.spui.abrirVisorPantalla(limpia(tv).innerHTML, tv.getAttribute('data-spui-tv-titulo') || 'Vista previa');
    });

    // Copia del TV como recién pintado (primera página, sin animación a
    // medias), para que el visor lo ponga en marcha de nuevo.
    function limpia(tv) {
        var clon = tv.cloneNode(true);
        clon.querySelectorAll('[data-spui-tv]').forEach(function (e) {
            delete e.dataset.spuiTvListo;
            delete e.dataset.spuiTvAjustado;
        });
        // Las páginas se vuelven a armar en el visor (otro tamaño, otra
        // animación): queda sólo la lista original.
        clon.querySelectorAll('[data-spui-tv-pagina]').forEach(function (p) { p.remove(); });
        clon.querySelectorAll('[data-spui-tv-origen]').forEach(function (o) { o.hidden = false; });
        clon.querySelectorAll('[data-spui-tv-avance]').forEach(function (a) { a.innerHTML = ''; a.hidden = true; });
        clon.querySelectorAll('[data-spui-tv-ajustar]').forEach(function (e) { e.style.fontSize = ''; });
        return clon;
    }

    window.spui = window.spui || {};
    window.spui.vistaTv = { iniciar: iniciar };
}());
