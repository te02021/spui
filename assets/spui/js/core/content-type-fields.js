// Muestra/oculta campos del form de contenido según el tipo seleccionado.
// Cubre el alta (contenidos/_form, el tipo sale del <select>) y la edición
// (contenidos/_edit_form, el tipo es fijo y viene en data-spui-tipo-fijo).
//
// Desde noviembre 2026 también maneja la parte "Cómo se ve en pantalla"
// (contenidos/_presentacion), con atributos genéricos:
//   data-spui-solo-tipos="a b"   visible sólo con esos tipos de contenido
// y, en el ítem de un cronograma, data-spui-si-tipo-item (días o fecha).
(function () {
    // El select lo renderiza Symfony con su propio id (contenido_tipo), así que
    // el gancho estable es el data-* que declara ContenidoType.
    var SELECTOR_TIPO = '[data-spui-tipo-contenido]';

    // La plantilla con la que arranca cada tipo (PlantillaPantalla::porDefecto).
    var PLANTILLA_POR_TIPO = { imagen: 'ninguna', video: 'ninguna' };

    function tipoDe(form) {
        var select = form.querySelector(SELECTOR_TIPO);
        if (select) return select.value;
        var fijo = form.querySelector('[data-spui-tipo-fijo]');
        return fijo ? fijo.getAttribute('data-spui-tipo-fijo') : '';
    }

    function elegido(form, name) {
        var r = form.querySelector('input[name="' + name + '"]:checked');
        return r ? r.value : '';
    }

    // ── Mostrar y ocultar con suavidad (02/11/2026) ─────────────────────────
    // Al elegir un tipo, lo que aparece se despliega de a poco (alto y
    // opacidad) en vez de saltar; lo que se va, se pliega. El modal crece con
    // el contenido, así que también se agranda suave. Sin animación al abrir
    // el formulario ni si el sistema pide reducir el movimiento.
    var ENTRA_MS = 520, SALE_MS = 260, CURVA = 'cubic-bezier(.22, .61, .36, 1)';
    var sinMovimiento = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function caja(el) {
        var cs = getComputedStyle(el);
        return { height: el.offsetHeight + 'px', marginTop: cs.marginTop, marginBottom: cs.marginBottom };
    }

    function poner(el, visible, animar) {
        if (el.getAnimations) el.getAnimations().forEach(function (a) { a.cancel(); });
        if (el.hidden === !visible) return;
        if (!animar || sinMovimiento || !el.animate) { el.hidden = !visible; return; }

        el.style.overflow = 'hidden';
        var fin = function () { el.style.overflow = ''; };
        if (visible) {
            el.hidden = false;
            var c = caja(el);
            el.animate([
                { height: '0px', marginTop: '0px', marginBottom: '0px', opacity: 0, transform: 'translateY(-6px)' },
                { height: c.height, marginTop: c.marginTop, marginBottom: c.marginBottom, opacity: 1, transform: 'none' },
            ], { duration: ENTRA_MS, easing: CURVA }).onfinish = fin;
        } else {
            var d = caja(el);
            var a = el.animate([
                { height: d.height, marginTop: d.marginTop, marginBottom: d.marginBottom, opacity: 1 },
                { height: '0px', marginTop: '0px', marginBottom: '0px', opacity: 0 },
            ], { duration: SALE_MS, easing: 'ease-in' });
            a.onfinish = function () { el.hidden = true; fin(); };
        }
    }

    function alternar(form, atributo, valor, animar) {
        form.querySelectorAll('[' + atributo + ']').forEach(function (el) {
            poner(el, el.getAttribute(atributo).split(' ').indexOf(valor) !== -1, animar);
        });
    }

    // Al cambiar de tipo en el alta, la plantilla pasa a la de ese tipo,
    // salvo que la persona ya haya elegido una a mano.
    function plantillaPorDefecto(form, tipo) {
        var radios = form.querySelectorAll('input[name="plantilla"]');
        if (!radios.length || form.dataset.spuiPlantillaElegida === '1') return;
        var valor = PLANTILLA_POR_TIPO[tipo] || 'institucional';
        radios.forEach(function (r) { r.checked = r.value === valor; });
    }

    function actualizarPresentacion(form, tipo, animar) {
        alternar(form, 'data-spui-solo-tipos', tipo, animar);
    }

    function actualizarCampos(form, animar) {
        var tipo = tipoDe(form);
        if (form.querySelector(SELECTOR_TIPO)) actualizarAlta(form, tipo, animar);
        actualizarPresentacion(form, tipo, animar);
    }

    function actualizarAlta(form, tipo, animar) {
        var campoArchivo    = form.querySelector('#campoArchivo');
        var campoTexto      = form.querySelector('#campoTexto');
        var campoCronograma = form.querySelector('#campoCronograma');
        var campoQr         = form.querySelector('#campoQr');
        var labelTexto      = form.querySelector('#labelTexto');
        var textarea        = form.querySelector('#textareaTexto');
        var archivoInput    = form.querySelector('#archivoInput');
        var qrUrl           = form.querySelector('#qrUrlDestino');

        var esArchivo    = tipo === 'imagen' || tipo === 'video';
        // El QR no se carga como texto: se cargan acá mismo el destino y el
        // vencimiento, y el CMS genera la imagen — así los escaneos se pueden
        // contar y no hay que pasar antes por ninguna otra pantalla.
        var esTexto      = tipo === 'texto';
        var esCronograma = tipo === 'cronograma';
        var esQr         = tipo === 'qr';

        if (campoArchivo)    poner(campoArchivo, esArchivo, animar);
        if (campoTexto)      poner(campoTexto, esTexto, animar);
        if (campoCronograma) poner(campoCronograma, esCronograma, animar);
        if (campoQr)         poner(campoQr, esQr, animar);

        // Un video dura lo que dura su archivo (lo lee el CMS al guardar) y un
        // cronograma lo que tardan sus páginas (lo calcula la Pi): en los dos
        // no se pide la duración, se explica de dónde sale.
        var fija = { video: 'Video', cronograma: 'Cronograma' }[tipo];
        var campoDuracion = form.querySelector('#campoDuracion');
        if (campoDuracion) campoDuracion.hidden = !!fija;
        ['Video', 'Cronograma'].forEach(function (t) {
            var nota = form.querySelector('#notaDuracion' + t);
            if (nota) nota.hidden = fija !== t;
        });
        if (archivoInput)    archivoInput.required = esArchivo;
        if (textarea)        textarea.required     = esTexto;
        if (qrUrl)           qrUrl.required        = esQr;

        // Formatos del archivo según el tipo: sólo los que corresponden (una
        // imagen no admite MP4), en el selector de archivos del sistema, en la
        // validación en vivo y en el texto de ayuda.
        if (archivoInput && archivoInput.dataset.spuiFormatosPorTipo && esArchivo) {
            var formatos = {};
            try { formatos = JSON.parse(archivoInput.dataset.spuiFormatosPorTipo); } catch (e) { /* sin datos: no se restringe */ }
            var f = formatos[tipo];
            if (f) {
                archivoInput.accept = f.accept;
                archivoInput.dataset.spuiFormatos = f.extensiones;
                archivoInput.dataset.spuiFormatosTexto = f.texto;
                var ayudaArchivo = form.querySelector('#archivoAyuda');
                if (ayudaArchivo) ayudaArchivo.textContent = (tipo === 'imagen' ? 'Imágenes' : 'Videos') + ': ' + f.texto + '.';
                // Si ya había un archivo elegido (de otro tipo), revisarlo de nuevo.
                if (archivoInput.files && archivoInput.files.length) {
                    archivoInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
        }

        if (labelTexto && labelTexto.childNodes[0]) {
            labelTexto.childNodes[0].textContent = 'Texto a mostrar ';
        }
    }

    // Ítem de un cronograma (cronograma/_item_form): días de la semana para
    // una clase o actividad, fecha para un evento especial.
    function actualizarItem(form, animar) {
        alternar(form, 'data-spui-si-tipo-item', elegido(form, 'tipo_item') || 'clase', animar);
    }

    // Estado inicial: al cargar la página y tras inyectar el form en el modal.
    function inicializar() {
        document.querySelectorAll('form[data-spui-item-cronograma]').forEach(function (f) { actualizarItem(f); });
        document.querySelectorAll('form').forEach(function (form) {
            if (!form.querySelector(SELECTOR_TIPO + ', [data-spui-tipo-fijo]')) return;
            // Un alta sin plantilla todavía (recién abierta) toma la del tipo.
            if (!elegido(form, 'plantilla')) plantillaPorDefecto(form, tipoDe(form));
            actualizarCampos(form);
        });
    }
    document.addEventListener('DOMContentLoaded', inicializar);
    document.addEventListener('spui:modal:loaded', inicializar);

    // Cambios por interacción (delegación; cubre el form inyectado en el modal).
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (!t || !t.form) return;
        // Los botones de tipo mandan sobre el <select>, que es el dato que viaja.
        if (t.hasAttribute('data-spui-tipo-opcion')) {
            var tipoSelect = t.form.querySelector(SELECTOR_TIPO);
            if (tipoSelect) {
                tipoSelect.value = t.value;
                tipoSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
            return;
        }
        // Lo que cambia por algo que eligió la persona, con animación.
        if (t.matches(SELECTOR_TIPO)) {
            plantillaPorDefecto(t.form, t.value);
            actualizarCampos(t.form, true);
        } else if (t.name === 'tipo_item') {
            actualizarItem(t.form, true);
        } else if (t.name === 'plantilla') {
            t.form.dataset.spuiPlantillaElegida = '1';
            actualizarCampos(t.form, true);
        }
    });
}());
