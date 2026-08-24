// Refresco automatico de las zonas con datos que cambian solos.
//
// Los reproductores reportan cada 60 s y el mantenimiento marca caidos cada
// minuto, asi que la pantalla envejece sin que nadie la toque: un equipo puede
// figurar conectado varios minutos despues de haberse apagado. Este modulo
// vuelve a pedir la pagina cada tanto y reemplaza solo las zonas marcadas.
//
// Uso desde Twig: envolver la zona en un contenedor con data-spui-refresh y un
// id, y el modulo la descubre solo.
//
//     <div id="dashboard-stats" data-spui-refresh>...</div>
//
// Reusa el patron fetch + DOMParser que ya usan modal.js y dashboard/index.js.
(function () {
    'use strict';

    window.spui = window.spui || {};

    // 20 s contra datos que cambian cada 60: alcanza para que nada se vea
    // viejo, sin multiplicar peticiones al pedo.
    var INTERVALO_MS = 20000;

    var enVuelo = false;
    var timer   = null;

    function zonas() {
        return document.querySelectorAll('[data-spui-refresh]');
    }

    /**
     * Aplica el HTML nuevo tocando solo lo que realmente cambio.
     *
     * Reemplazar innerHTML entero funciona, pero cada 20 s destruye y recrea
     * toda la tabla: se pierde la seleccion de texto, el scroll interno salta,
     * y hay que grisar la zona para disimular el parpadeo. Con datos que casi
     * nunca cambian entre ciclos, es mucho ruido para nada.
     *
     * Este recorrido compara nodo por nodo y solo escribe donde hay diferencia.
     * Si el ciclo no trajo novedades, el DOM no se toca en absoluto.
     *
     * @returns {number} cuantos nodos de texto se actualizaron
     */
    function aplicarCambios(viejo, nuevo) {
        var cambios = 0;

        // Distinta cantidad de hijos: cambio estructural (una fila nueva, una
        // alerta que aparecio). No hay forma prolija de reconciliar eso nodo a
        // nodo, asi que se reemplaza el bloque entero.
        if (viejo.children.length !== nuevo.children.length) {
            viejo.innerHTML = nuevo.innerHTML;
            return 1;
        }

        // Hoja del arbol: comparar el texto directamente.
        if (viejo.children.length === 0) {
            // Los tiempos relativos los reescribe tiempo-relativo.js cada 10 s,
            // asi que su texto casi nunca coincide con el que acaba de mandar
            // el servidor ("hace 3 min" vs "hace 2 min"). Compararlos haria
            // destellar la columna en cada ciclo sin que haya novedad real: lo
            // unico que importa de estos nodos es el timestamp, que ya se
            // sincroniza en copiarAtributos().
            if (viejo.hasAttribute('data-spui-desde')) {
                copiarAtributos(viejo, nuevo);
                return cambios;
            }

            if (viejo.textContent.trim() !== nuevo.textContent.trim()) {
                viejo.textContent = nuevo.textContent;
                destellar(viejo);
                cambios++;
            }
            copiarAtributos(viejo, nuevo);
            return cambios;
        }

        copiarAtributos(viejo, nuevo);

        // Texto suelto MEZCLADO con elementos hijos. Es el caso de la celda de
        // heartbeat:
        //
        //     <td> 15/08 12:31:26 <br> <small>hace 2 min</small> </td>
        //
        // La fecha es un nodo de texto directo del <td>, que ademas tiene dos
        // hijos. Al recorrer solo children se saltaba ese texto y la fecha
        // quedaba congelada hasta cambiar de pagina.
        cambios += sincronizarTextoSuelto(viejo, nuevo);

        for (var i = 0; i < viejo.children.length; i++) {
            var a = viejo.children[i];
            var b = nuevo.children[i];

            // Si cambio el tipo de elemento, no tiene sentido seguir bajando.
            if (a.tagName !== b.tagName) {
                a.replaceWith(b.cloneNode(true));
                cambios++;
                continue;
            }

            cambios += aplicarCambios(a, b);
        }

        return cambios;
    }

    /**
     * Actualiza los nodos de texto que cuelgan directamente de un elemento,
     * sin recorrer sus hijos elemento.
     */
    function sincronizarTextoSuelto(viejo, nuevo) {
        var cambios = 0;

        var textosViejos = [];
        var textosNuevos = [];

        for (var i = 0; i < viejo.childNodes.length; i++) {
            if (viejo.childNodes[i].nodeType === 3) textosViejos.push(viejo.childNodes[i]);
        }
        for (var j = 0; j < nuevo.childNodes.length; j++) {
            if (nuevo.childNodes[j].nodeType === 3) textosNuevos.push(nuevo.childNodes[j]);
        }

        // Si la cantidad no coincide, la estructura cambio y no hay forma
        // confiable de emparejarlos; lo resuelve el reemplazo del bloque.
        if (textosViejos.length !== textosNuevos.length) return 0;

        for (var k = 0; k < textosViejos.length; k++) {
            if (textosViejos[k].textContent.trim() === textosNuevos[k].textContent.trim()) continue;
            textosViejos[k].textContent = textosNuevos[k].textContent;
            destellar(textosViejos[k]);
            cambios++;
        }

        return cambios;
    }

    /**
     * Sincroniza atributos que el servidor puede haber cambiado.
     *
     * Interesan sobre todo class (badges de estado, .is-stale) y los data-*
     * que usan otros modulos. No se copian todos a ciegas para no pisar cosas
     * que el navegador maneja, como el estado de un input.
     */
    function copiarAtributos(viejo, nuevo) {
        if (viejo.className !== nuevo.className) {
            viejo.className = nuevo.className;
        }
        for (var i = 0; i < nuevo.attributes.length; i++) {
            var attr = nuevo.attributes[i];
            if (attr.name.indexOf('data-') !== 0) continue;
            if (viejo.getAttribute(attr.name) !== attr.value) {
                viejo.setAttribute(attr.name, attr.value);
            }
        }
    }

    /**
     * Resalta un valor recien actualizado durante medio segundo.
     *
     * Sin esto el refresco es tan silencioso que uno duda de si esta
     * funcionando; y cuando un reproductor se cae, el cambio de estado pasa
     * desapercibido. El destello dura poco y no desplaza nada.
     */
    function destellar(el) {
        var destino = el.nodeType === 1 ? el : el.parentElement;
        if (!destino) return;
        destino.classList.remove('spui-destello');
        // Forzar reflow para poder reiniciar la animacion si el mismo valor
        // vuelve a cambiar antes de que termine la anterior.
        void destino.offsetWidth;
        destino.classList.add('spui-destello');
        setTimeout(function () { destino.classList.remove('spui-destello'); }, 900);
    }

    // No refrescar con un modal abierto: el usuario puede estar escribiendo en
    // un formulario, y reemplazar el HTML de la tabla de atras le borraria lo
    // tipeado o dejaria el modal apuntando a una fila que ya no existe.
    function hayModalAbierto() {
        return !!document.querySelector('.spui-modal-overlay.is-open, .modal-overlay.is-open, [class*="overlay"].is-open');
    }

    function debePausar() {
        // document.hidden cubre la pestana en segundo plano y la ventana
        // minimizada. Sin esto, una pestana olvidada golpea el servidor toda
        // la noche sin que nadie mire el resultado.
        return document.hidden || hayModalAbierto();
    }

    /**
     * Pide la pagina actual y reemplaza el contenido de cada zona marcada.
     *
     * Se manda X-Requested-With para que el controlador pueda devolver solo el
     * fragmento en vez de la pagina entera con sidebar, footer y modales.
     */
    function refrescar(onDone, mostrarProgreso) {
        var objetivos = zonas();
        if (!objetivos.length) { if (onDone) onDone(); return; }

        // Si el ciclo anterior sigue esperando respuesta, saltear este. Con el
        // servidor lento, encolar peticiones solo empeora las cosas.
        if (enVuelo) { if (onDone) onDone(); return; }
        enVuelo = true;

        // El indicador de carga solo se muestra cuando el refresco lo pidio el
        // usuario. En el ciclo automatico seria un parpadeo cada 20 s sobre una
        // tabla que casi siempre queda igual.
        if (mostrarProgreso) {
            objetivos.forEach(function (el) { el.classList.add('is-refreshing'); });
        }

        fetch(location.pathname + location.search, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store',
            credentials: 'same-origin'
        })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');

                var totalCambios = 0;

                objetivos.forEach(function (el) {
                    if (!el.id) return;

                    var fresco = doc.getElementById(el.id);

                    if (!fresco && objetivos.length === 1) {
                        // El servidor devolvio solo el fragmento, sin el
                        // contenedor que lo envuelve: su contenido ES la
                        // respuesta entera. Sirve para que el controlador no
                        // tenga que repetir el div envoltorio en el partial.
                        fresco = doc.body;
                    }
                    if (!fresco) return;

                    totalCambios += aplicarCambios(el, fresco);
                });

                // Solo si algo cambio: si el DOM quedo igual, los listeners
                // siguen asociados a los mismos nodos de siempre.
                if (totalCambios > 0 && window.spui.bindTableButtons) {
                    window.spui.bindTableButtons();
                }

                // Los tiempos relativos del HTML recien llegado todavia no
                // fueron formateados.
                if (window.spui.actualizarTiempos) {
                    window.spui.actualizarTiempos();
                }

                document.dispatchEvent(new CustomEvent('spui:refrescado'));
            })
            .catch(function () {
                // Silencioso a proposito: un corte de red momentaneo no
                // justifica molestar al usuario, y el proximo ciclo reintenta.
            })
            .finally(function () {
                enVuelo = false;
                objetivos.forEach(function (el) { el.classList.remove('is-refreshing'); });
                if (onDone) onDone();
            });
    }

    function tick() {
        if (debePausar()) return;
        refrescar();
    }

    function arrancar() {
        if (timer || !zonas().length) return;
        timer = setInterval(tick, INTERVALO_MS);
    }

    function detener() {
        if (!timer) return;
        clearInterval(timer);
        timer = null;
    }

    // Refresco manual, para los botones "Actualizar". Ignora la pausa por
    // pestana oculta: si el usuario lo pidio, es porque esta mirando.
    window.spui.refrescarAhora = function (boton) {
        if (boton) {
            boton.disabled = true;
            var icono = boton.querySelector('img');
            if (icono) icono.style.animation = 'spin .8s linear infinite';
        }
        refrescar(function () {
            if (!boton) return;
            boton.disabled = false;
            var icono = boton.querySelector('img');
            if (icono) icono.style.animation = '';
        }, true);   // con indicador: el usuario lo pidio y espera feedback
    };

    document.addEventListener('DOMContentLoaded', function () {
        arrancar();

        // Al volver a la pestana, refrescar en el acto en vez de esperar el
        // proximo tick: es justo cuando el usuario mira, y los datos pueden
        // llevar minutos sin actualizarse.
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && !hayModalAbierto()) refrescar();
        });

        // Botones "Actualizar" declarativos.
        document.querySelectorAll('[data-spui-refresh-btn]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                window.spui.refrescarAhora(btn);
            });
        });
    });

    window.spui.autoRefresh = { arrancar: arrancar, detener: detener, refrescar: refrescar };
}());
