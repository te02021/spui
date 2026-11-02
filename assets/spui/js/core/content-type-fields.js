// Muestra/oculta campos del form de contenido según el tipo seleccionado.
// Cubre el alta (contenidos/_form, el tipo sale del <select>) y la edición
// (contenidos/_edit_form, el tipo es fijo y viene en data-spui-tipo-fijo).
//
// Desde noviembre 2026 también maneja la parte "Cómo se ve en pantalla"
// (contenidos/_presentacion), con atributos genéricos:
//   data-spui-solo-tipos="a b"   visible sólo con esos tipos de contenido
//   data-spui-si-plantilla="x"   visible sólo con esa plantilla elegida
//   data-spui-si-origen="x"      visible sólo con ese origen de cronograma
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

    function alternar(form, atributo, valor) {
        form.querySelectorAll('[' + atributo + ']').forEach(function (el) {
            el.hidden = el.getAttribute(atributo).split(' ').indexOf(valor) === -1;
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

    function actualizarPresentacion(form, tipo) {
        alternar(form, 'data-spui-solo-tipos', tipo);
        // Un video va siempre sin plantilla (y así lo manda el servidor).
        alternar(form, 'data-spui-si-plantilla', tipo === 'video' ? 'ninguna' : elegido(form, 'plantilla'));
        alternar(form, 'data-spui-si-origen', elegido(form, 'cronograma_origen') || 'propio');
    }

    function actualizarCampos(form) {
        var tipo = tipoDe(form);
        if (form.querySelector(SELECTOR_TIPO)) actualizarAlta(form, tipo);
        actualizarPresentacion(form, tipo);
    }

    function actualizarAlta(form, tipo) {
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

        if (campoArchivo)    campoArchivo.style.display    = esArchivo    ? '' : 'none';
        if (campoTexto)      campoTexto.style.display      = esTexto      ? '' : 'none';
        if (campoCronograma) campoCronograma.style.display = esCronograma ? '' : 'none';
        if (campoQr)         campoQr.style.display         = esQr         ? '' : 'none';

        // Un video dura lo que dura su archivo (lo lee el CMS al guardar) y un
        // cronograma lo que tardan sus páginas (lo calcula la Pi): en los dos
        // no se pide la duración, se explica de dónde sale.
        var fija = { video: 'Video', cronograma: 'Cronograma' }[tipo];
        var campoDuracion = form.querySelector('#campoDuracion');
        if (campoDuracion) campoDuracion.hidden = !!fija;
        ['Video', 'Cronograma'].forEach(function (t) {
            var nota = form.querySelector('#notaDuracion' + t);
            var ayudaTipo = form.querySelector('#ayudaDuracion' + t);
            if (nota) nota.hidden = fija !== t;
            if (ayudaTipo) ayudaTipo.hidden = fija !== t;
        });
        // Las ayudas van debajo de la fila título/tipo/duración (30/10/2026).
        var ayuda = form.querySelector('#ayudaDuracion');
        if (ayuda) ayuda.hidden = !!fija;
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
    function actualizarItem(form) {
        alternar(form, 'data-spui-si-tipo-item', elegido(form, 'tipo_item') || 'clase');
    }

    // Estado inicial: al cargar la página y tras inyectar el form en el modal.
    function inicializar() {
        document.querySelectorAll('form[data-spui-item-cronograma]').forEach(actualizarItem);
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
        if (t.matches(SELECTOR_TIPO)) {
            plantillaPorDefecto(t.form, t.value);
            actualizarCampos(t.form);
        } else if (t.name === 'tipo_item') {
            actualizarItem(t.form);
        } else if (t.name === 'plantilla' || t.name === 'cronograma_origen') {
            if (t.name === 'plantilla') t.form.dataset.spuiPlantillaElegida = '1';
            actualizarCampos(t.form);
        }
    });
}());
