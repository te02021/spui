// Muestra/oculta campos del form de contenido según el tipo seleccionado.
// Cubre la página `contenidos/new` y el modal `contenidos/_form` (con cronograma).
(function () {
    // El select lo renderiza Symfony con su propio id (contenido_tipo), así que
    // el gancho estable es el data-* que declara ContenidoType.
    var SELECTOR_TIPO = '[data-spui-tipo-contenido]';

    function actualizarCampos() {
        var tipoSelect = document.querySelector(SELECTOR_TIPO);
        if (!tipoSelect) return;

        var tipo            = tipoSelect.value;
        var campoArchivo    = document.getElementById('campoArchivo');
        var campoTexto      = document.getElementById('campoTexto');
        var campoCronograma = document.getElementById('campoCronograma');
        var campoQr         = document.getElementById('campoQr');
        var labelTexto      = document.getElementById('labelTexto');
        var textarea        = document.getElementById('textareaTexto');
        var archivoInput    = document.getElementById('archivoInput');
        var qrSelect        = document.getElementById('codigoQrSelect');

        var esArchivo    = tipo === 'imagen' || tipo === 'video';
        // El QR ya no se carga como texto: se elige un código de la lista y el
        // CMS genera la imagen, para que los escaneos se puedan contar.
        var esTexto      = tipo === 'texto' || tipo === 'youtube';
        var esCronograma = tipo === 'cronograma';
        var esQr         = tipo === 'qr';

        if (campoArchivo)    campoArchivo.style.display    = esArchivo    ? '' : 'none';
        if (campoTexto)      campoTexto.style.display      = esTexto      ? '' : 'none';
        if (campoCronograma) campoCronograma.style.display = esCronograma ? '' : 'none';
        if (campoQr)         campoQr.style.display         = esQr         ? '' : 'none';
        if (archivoInput)    archivoInput.required = esArchivo;
        if (textarea)        textarea.required     = esTexto;
        if (qrSelect)        qrSelect.required     = esQr;

        if (labelTexto && labelTexto.childNodes[0]) {
            labelTexto.childNodes[0].textContent =
                tipo === 'youtube' ? 'URL de YouTube ' : 'Texto a mostrar ';
        }
        if (textarea) {
            textarea.placeholder =
                tipo === 'youtube' ? 'https://www.youtube.com/watch?v=...' : 'Ingresá el texto a mostrar…';
        }
    }

    // Estado inicial: al cargar la página y tras inyectar el form en el modal.
    document.addEventListener('DOMContentLoaded', actualizarCampos);
    document.addEventListener('spui:modal:loaded', actualizarCampos);

    // Cambios por interacción (delegación; cubre el select inyectado en el modal).
    document.addEventListener('change', function (e) {
        if (e.target && e.target.matches && e.target.matches(SELECTOR_TIPO)) actualizarCampos();
    });
}());
