// Video elegido en el formulario de Contenido: antes de subirlo, lee su
// resolución y su duración en el navegador.
//
//   - Si pasa de lo que las pantallas reproducen fluido (data-spui-video-max,
//     por ejemplo "1920x1080"), avisa debajo del campo y Guardar no lo envía:
//     validacion-vivo.js respeta la marca data-spui-archivo-invalido.
//   - Si sirve, muestra cuánto dura: es la duración que va a tener en la
//     playlist, porque el CMS la toma del archivo.
//
// No reemplaza al servidor (VideoInspectorService), que además mira el códec y
// los cuadros por segundo, que el navegador no informa. Si el navegador no
// puede leer el archivo (un códec que no conoce), no se dice nada acá: decide
// el servidor al guardar.
(function () {
    'use strict';

    function esVideo(input) {
        return (input.dataset.spuiFormatos || '').split(',').indexOf('mp4') !== -1;
    }

    function duracionLegible(seg) {
        seg = Math.ceil(seg);
        if (seg < 60) return seg + ' s';
        var min = Math.floor(seg / 60), resto = seg % 60;
        return min + ' min' + (resto ? ' ' + resto + ' s' : '');
    }

    /** Línea de información debajo del campo (se crea la primera vez). */
    function info(input) {
        var cont = input.closest('[data-spui-campo]') || input.parentElement;
        var el = cont.querySelector('[data-spui-video-info]');
        if (!el) {
            el = document.createElement('small');
            el.className = 'text-muted d-block mt-1';
            el.setAttribute('data-spui-video-info', '');
            cont.appendChild(el);
        }
        return el;
    }

    function revisar(input) {
        delete input.dataset.spuiArchivoInvalido;
        var archivo = input.files && input.files[0];
        var linea = input.closest('[data-spui-campo]') && input.closest('[data-spui-campo]').querySelector('[data-spui-video-info]');
        if (linea) linea.remove();
        if (!archivo || !esVideo(input) || !input.dataset.spuiVideoMax) return;

        var max = input.dataset.spuiVideoMax.split('x').map(Number);   // [lado mayor, lado menor]
        var url = URL.createObjectURL(archivo);
        var video = document.createElement('video');
        video.preload = 'metadata';
        video.muted = true;

        video.onloadedmetadata = function () {
            var w = video.videoWidth, h = video.videoHeight, d = video.duration;
            URL.revokeObjectURL(url);
            if (input.files[0] !== archivo) return;   // ya eligió otro

            if (w && h && (Math.max(w, h) > max[0] || Math.min(w, h) > max[1])) {
                var msg = 'El video es de ' + w + '×' + h + ' y las pantallas reproducen fluido hasta ' +
                          max[0] + '×' + max[1] + ' (Full HD). Subí una versión en 1080p o menos: en una más grande la imagen se traba.';
                input.dataset.spuiArchivoInvalido = msg;
                if (window.spui.errorDeCampo) window.spui.errorDeCampo(input, msg);
                return;
            }
            if (isFinite(d) && d > 0) {
                info(input).textContent = 'Video de ' + w + '×' + h + ': va a durar ' + duracionLegible(d) + ' en la playlist.';
            }
        };
        video.onerror = function () { URL.revokeObjectURL(url); };
        video.src = url;
    }

    document.addEventListener('change', function (e) {
        var t = e.target;
        if (t && t.matches && t.matches('input[type="file"][data-spui-video-max]')) revisar(t);
    });
}());
