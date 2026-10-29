// Telemetría de un reproductor (templates/reproductores/telemetria.html.twig).
//
//   - Histórico por hora: tres gráficos ApexCharts con escala fija, agrupados
//     (se mueven y muestran el tooltip juntos). La página trae los últimos 60
//     días y los botones 24 h / 7 d / 30 d eligen qué ventana se ve. Arrastrar
//     hacia la izquierda muestra las horas anteriores; nunca se pasa de ahora
//     ni del primer dato. Cada minuto se vuelven a pedir los datos en silencio.
//   - Indicadores circulares: temperatura, RAM y disco en uso.
//   - En vivo: suscrito por WebSocket a spui/telemetria/vivo/{id}. Mientras la
//     pestaña está visible, se le pide a la Pi (POST /telemetria/vivo) que
//     publique cada 1 s, y se renueva a la mitad del plazo. Al cerrar u ocultar
//     la pestaña se deja de renovar y la Pi vuelve sola a publicar cada minuto.
//   - Exportar: el modal (_telemetria_exportar.html.twig) se envía desde acá;
//     lo que vuelve es un archivo, o un 422 con los errores por campo.
(function () {
    'use strict';

    var raiz = document.getElementById('spui-telemetria');
    if (!raiz || typeof window.ApexCharts === 'undefined') return;

    var AZUL = '#3387EF', AMBAR = '#C77F0A', ROJO = '#FF223D', VERDE = '#4A9930', VIOLETA = '#7A5AF8', GRIS = '#6c757d';
    var UMBRAL = parseFloat(raiz.dataset.umbral) || 80;
    var VIVO_SEG = parseInt(raiz.dataset.vivoSeg, 10) || 120;
    var VIVO_PUNTOS = 120;           // 2 minutos a una lectura por segundo
    var SIN_DATOS_VIVO_MS = 15000;   // pedido hecho y nada llegó: la Pi no responde
    var REFRESCO_MS = 60000;
    var CARGA_MIN_MS = 450;          // la animación de carga se ve aunque no haya pedido
    var HORAS = { '24h': 24, '7d': 168, '30d': 720 };
    var GRUPO = 'spui-telemetria';

    var datos = JSON.parse(raiz.querySelector('[data-tel-datos]').textContent);
    var rango = datos.rango;
    var vivoActivo = false;          // llegó al menos una muestra en vivo y no se pausó
    // Capacidad del disco (GB). Se cuenta lo EN USO contra ella, no lo libre
    // (pedido de Mateo, 29/10/2026). Null con clientes de la Pi anteriores a
    // esa fecha, que no la mandan: ahí se sigue mostrando lo libre, aclarado.
    var discoTotalGb = datos.ultima ? datos.ultima.disco_total_gb : null;
    /** GB en uso a partir de los MB libres; null si no se conoce la capacidad. */
    function enUsoGb(libreMb) { return discoTotalGb ? Math.max(0, discoTotalGb - libreMb / 1024) : null; }
    var ajustando = false;           // la vista la está moviendo este código (rango o arrastre)

    // ── Idioma de los gráficos ──────────────────────────────────────────────
    var ES = {
        name: 'es',
        options: {
            months: ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'],
            shortMonths: ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'],
            days: ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'],
            shortDays: ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'],
            toolbar: { exportToSVG: 'Descargar SVG', exportToPNG: 'Descargar PNG', exportToCSV: 'Descargar CSV',
                       selection: 'Selección', selectionZoom: 'Acercar una zona', zoomIn: 'Acercar', zoomOut: 'Alejar',
                       pan: 'Arrastrar para moverse', reset: 'Volver al período elegido' }
        }
    };

    function num(v, dec) { return (v === null || v === undefined || isNaN(v)) ? '—' : Number(v).toFixed(dec).replace('.', ','); }
    function colorTemp(t) { return t >= UMBRAL ? ROJO : (t >= UMBRAL * 0.85 ? AMBAR : VERDE); }
    function colorPorc(r) { return r >= 90 ? ROJO : (r >= 75 ? AMBAR : AZUL); }
    /** "2026-10-29 08:00" → milisegundos, en hora local (como lo guarda el CMS). */
    function ms(periodo) {
        var p = periodo.split(/[- :]/);
        return new Date(+p[0], +p[1] - 1, +p[2], +p[3], +(p[4] || 0)).getTime();
    }

    // ── Ventana visible ─────────────────────────────────────────────────────
    function limites() {
        var serie = datos.serie;
        var ahora = Date.now();
        var primero = serie.length ? ms(serie[0].periodo) : ahora - HORAS['30d'] * 3600e3;
        return { min: Math.min(primero, ahora - 3600e3), max: ahora };
    }
    function ventana(r) {
        var l = limites();
        return { min: Math.max(l.min, l.max - HORAS[r] * 3600e3), max: l.max };
    }
    function base(id, alto, extra, tipo) {
        var o = {
            chart: {
                id: id, group: GRUPO, type: tipo || 'area', height: alto, fontFamily: 'inherit', locales: [ES], defaultLocale: 'es',
                // Sin la mano (pan) ni la lupa de selección de ApexCharts: el
                // arrastre lo maneja arrastrarVentana() (ver más abajo).
                toolbar: { show: true,
                           tools: { download: true, selection: false, zoom: false, zoomin: true, zoomout: true, pan: false, reset: true } },
                zoom: { enabled: true, type: 'x', allowMouseWheelZoom: false },
                // Sin animación al mover la vista (arrastre cuadro a cuadro); la del
                // cambio de rango la pide mostrarVentana().
                animations: { enabled: true, easing: 'easeinout', speed: 700, dynamicAnimation: { enabled: false } },
                events: {
                    beforeResetZoom: function () { return { xaxis: ventana(rango) }; }
                }
            },
            stroke: { curve: 'smooth', width: 2.5 },
            dataLabels: { enabled: false },
            grid: { borderColor: '#e9ecef', strokeDashArray: 3 },
            legend: { position: 'top', horizontalAlign: 'left', fontSize: '13px' },
            xaxis: {
                type: 'datetime',
                labels: { datetimeUTC: false, style: { colors: GRIS }, datetimeFormatter: { day: 'dd MMM', hour: 'HH:mm' } },
                tooltip: { enabled: false }
            },
            tooltip: { shared: true, x: { format: 'dd/MM/yyyy HH:mm' } },
            noData: { text: 'Sin datos en este período', style: { color: GRIS } }
        };
        return Object.assign(o, extra);
    }
    // Los gráficos agrupados se alinean sólo si los ejes Y miden lo mismo.
    var ANCHO_EJE = 62;

    // ── Gráficos del histórico ──────────────────────────────────────────────
    function series(serie) {
        // Un punto vacío en "ahora" al final de cada serie: ApexCharts frena el
        // arrastre en el último dato que conoce, y sin esto ese límite quedaba
        // antes de la ventana (que termina en ahora) y el arrastre saltaba.
        var ahora = Date.now();
        function conAhora(datos) { datos.push([ahora, null]); return datos; }
        return {
            temp: [
                { name: 'Promedio', data: conAhora(serie.map(function (p) { return [ms(p.periodo), p.temp_prom]; })) },
                { name: 'Máxima',   data: conAhora(serie.map(function (p) { return [ms(p.periodo), p.temp_max]; })) }
            ],
            ram:   [{ name: 'RAM promedio', data: conAhora(serie.map(function (p) { return [ms(p.periodo), p.ram_prom]; })) }],
            // Por hora, el máximo en uso = capacidad − mínimo libre de esa hora.
            disco: [{ name: discoTotalGb ? 'Disco en uso' : 'Disco libre',
                      data: conAhora(serie.map(function (p) {
                          var gb = discoTotalGb ? enUsoGb(p.disco_min) : p.disco_min / 1024;
                          return [ms(p.periodo), Math.round(gb * 10) / 10];
                      })) }]
        };
    }
    function maxTemp(serie) {
        var m = serie.reduce(function (a, p) { return Math.max(a, p.temp_max || 0); }, 0);
        return Math.max(100, Math.ceil(m / 10) * 10);
    }
    /**
     * Tope del eje del disco: la capacidad total, que es el límite real. Sin
     * ella, 4 marcas de un paso "redondo" (25, 40, 50 GB…) con aire arriba.
     */
    function maxDisco(s) {
        if (discoTotalGb) return discoTotalGb;
        var m = s.disco[0].data.reduce(function (a, p) { return Math.max(a, p[1] || 0); }, 0);
        var pasos = [0.5, 1, 2, 2.5, 5, 10, 20, 25, 40, 50, 75, 100, 150, 200, 250, 500];
        var paso = pasos.filter(function (p) { return p * 4 >= m * 1.15; })[0] || 500;
        return paso * 4;
    }

    var s0 = series(datos.serie);
    var graf = {};
    graf.temp = new ApexCharts(raiz.querySelector('[data-tel-chart="temp"]'), base('tel-temp', 320, {
        series: s0.temp,
        colors: [AZUL, AMBAR],
        fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.02 } },
        yaxis: { min: 0, max: maxTemp(datos.serie), tickAmount: 5,
                 labels: { minWidth: ANCHO_EJE, maxWidth: ANCHO_EJE, formatter: function (v) { return Math.round(v) + ' °C'; } } },
        tooltip: { shared: true, x: { format: 'dd/MM/yyyy HH:mm' }, y: { formatter: function (v) { return num(v, 1) + ' °C'; } } },
        annotations: { yaxis: [{ y: UMBRAL, borderColor: ROJO, strokeDashArray: 6,
            label: { text: 'Umbral ' + UMBRAL + ' °C', borderColor: ROJO, style: { color: '#fff', background: ROJO } } }] }
    }));
    graf.ram = new ApexCharts(raiz.querySelector('[data-tel-chart="ram"]'), base('tel-ram', 260, {
        series: s0.ram,
        colors: [AZUL],
        fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.02 } },
        yaxis: { min: 0, max: 100, tickAmount: 5,
                 labels: { minWidth: ANCHO_EJE, maxWidth: ANCHO_EJE, formatter: function (v) { return Math.round(v) + ' %'; } } },
        tooltip: { x: { format: 'dd/MM/yyyy HH:mm' }, y: { formatter: function (v) { return num(v, 1) + ' %'; } } }
    }));
    graf.disco = new ApexCharts(raiz.querySelector('[data-tel-chart="disco"]'), base('tel-disco', 260, {
        series: s0.disco,
        colors: [VIOLETA],
        fill: { type: 'gradient', gradient: { opacityFrom: 0.3, opacityTo: 0.02 } },
        yaxis: { min: 0, max: maxDisco(s0), tickAmount: 4,
                 labels: { minWidth: ANCHO_EJE, maxWidth: ANCHO_EJE, formatter: function (v) { return num(v, (v === 0 || v >= 10) ? 0 : 1) + ' GB'; } } },
        tooltip: { x: { format: 'dd/MM/yyyy HH:mm' }, y: { formatter: function (v) {
            return num(v, 1) + ' GB' + (discoTotalGb ? ' (' + num(v / discoTotalGb * 100, 0) + ' %)' : '');
        } } },
        annotations: discoTotalGb ? { yaxis: [{ y: discoTotalGb, borderColor: ROJO, strokeDashArray: 6,
            // A la izquierda y debajo de la línea: arriba, a la derecha, está la barra de íconos.
            label: { text: 'Capacidad ' + num(discoTotalGb, 0) + ' GB', position: 'left', textAnchor: 'start', offsetX: 6, offsetY: 22,
                     borderColor: ROJO, style: { color: '#fff', background: ROJO } } }] } : {}
    }));
    var LISTA = [graf.temp, graf.ram, graf.disco];
    LISTA.forEach(function (g) { g.render(); });

    /** Muestra la ventana de un rango, con la animación de dibujo de ApexCharts. */
    function mostrarVentana(r, animar) {
        var v = ventana(r);
        ajustando = true;
        LISTA.forEach(function (g) {
            try { g.updateOptions({ xaxis: { min: v.min, max: v.max } }, false, !!animar); } catch (e) { /* sin datos */ }
        });
        ajustando = false;
    }
    mostrarVentana(rango);

    /**
     * Arrastre propio: mantener apretado y mover hacia la derecha muestra las
     * horas anteriores. El pan de ApexCharts avanza a saltos de 1/15 de la
     * ventana por cada movimiento del mouse (11 h con 7 días) y rechaza el
     * salto entero si se pasa del límite, así que cerca de "ahora" no se podía
     * volver hasta el final. Acá la vista sigue al puntero y frena justo en
     * ahora y en el primer dato. Los tres gráficos se mueven juntos.
     */
    var cuadroPendiente = null;
    function arrastrarVentana(contenedor, grafico) {
        var inicio = null;
        contenedor.classList.add('spui-tel-arrastrable');
        contenedor.addEventListener('pointerdown', function (e) {
            if (e.button !== 0 || e.target.closest('.apexcharts-toolbar, .apexcharts-legend')) return;
            var g = grafico.w.globals;
            inicio = { x: e.clientX, min: g.minX, max: g.maxX, msPorPx: (g.maxX - g.minX) / Math.max(1, g.gridWidth) };
            contenedor.setPointerCapture(e.pointerId);
            contenedor.classList.add('is-arrastrando');
        });
        contenedor.addEventListener('pointermove', function (e) {
            if (!inicio) return;
            var l = limites(), span = inicio.max - inicio.min;
            var min = inicio.min - (e.clientX - inicio.x) * inicio.msPorPx;
            min = Math.max(l.min, Math.min(min, l.max - span));
            if (cuadroPendiente !== null) cancelAnimationFrame(cuadroPendiente);
            cuadroPendiente = requestAnimationFrame(function () {
                cuadroPendiente = null;
                ajustando = true;
                LISTA.forEach(function (c) { c.zoomX(min, min + span); });
                ajustando = false;
            });
        });
        function soltar() {
            inicio = null;
            contenedor.classList.remove('is-arrastrando');
        }
        contenedor.addEventListener('pointerup', soltar);
        contenedor.addEventListener('pointercancel', soltar);
    }
    LISTA.forEach(function (g) { arrastrarVentana(g.el, g); });

    // ── Indicadores circulares ──────────────────────────────────────────────
    function gauge(el, color) {
        var g = new ApexCharts(el, {
            series: [0],
            chart: { type: 'radialBar', height: 230, fontFamily: 'inherit',
                     animations: { enabled: true, easing: 'easeout', speed: 1100, dynamicAnimation: { enabled: true, speed: 500 } } },
            colors: [color],
            plotOptions: { radialBar: {
                startAngle: -135, endAngle: 135,
                hollow: { size: '62%' },
                track: { background: '#eef1f4', strokeWidth: '100%' },
                dataLabels: { name: { show: false }, value: { offsetY: 10, fontSize: '30px', fontWeight: 700, color: '#2b3a4a',
                                                               formatter: function () { return el._texto || '—'; } } }
            } },
            stroke: { lineCap: 'round' }
        });
        g.render();
        return g;
    }
    var gTemp  = gauge(raiz.querySelector('[data-tel-gauge="temp"]'), VERDE);
    var gRam   = gauge(raiz.querySelector('[data-tel-gauge="ram"]'), AZUL);
    var gDisco = gauge(raiz.querySelector('[data-tel-gauge="disco"]'), VIOLETA);

    function ponerGauge(g, valor, texto, color) {
        g.el._texto = texto;
        g.updateOptions({ colors: [color] }, false, false);
        g.updateSeries([Math.max(0, Math.min(100, valor || 0))]);
    }
    function indicadores(temp, ram, discoLibreGb, totalGb) {
        if (temp !== null && temp !== undefined) ponerGauge(gTemp, temp, num(temp, 1) + ' °C', colorTemp(temp));
        if (ram !== null && ram !== undefined) ponerGauge(gRam, ram, num(ram, 1) + ' %', colorPorc(ram));
        if (totalGb) discoTotalGb = totalGb;
        if (discoLibreGb !== null && discoLibreGb !== undefined) {
            var etiqueta = raiz.querySelector('[data-tel-disco-etq]');
            if (discoTotalGb) {
                var usoGb = Math.max(0, discoTotalGb - discoLibreGb);
                var uso = usoGb / discoTotalGb * 100;
                ponerGauge(gDisco, uso, num(uso, 0) + ' %', colorPorc(uso));
                etiqueta.textContent = 'En uso';
                raiz.querySelector('[data-tel-numero="disco"]').textContent = num(usoGb, 1) + ' GB';
                raiz.querySelector('[data-tel-numero="disco_total"]').textContent = num(discoTotalGb, 0) + ' GB';
            } else {
                // Clientes anteriores al 29/10/2026 no mandan el tamaño del disco.
                gDisco.el._texto = num(discoLibreGb, 0) + ' GB';
                gDisco.updateSeries([0]);
                etiqueta.textContent = 'Libre';
                raiz.querySelector('[data-tel-numero="disco"]').textContent = num(discoLibreGb, 1) + ' GB';
            }
            discoActualGb = discoTotalGb ? discoTotalGb - discoLibreGb : discoLibreGb;
            maxDiscoDelPeriodo();
        }
    }

    function resumen(r) {
        var txt = {
            temp_prom: r ? num(r.temp_prom, 1) + ' °C' : '—',
            temp_max:  r ? num(r.temp_max, 1) + ' °C' : '—',
            ram_prom:  r ? num(r.ram_prom, 1) + ' %' : '—',
            ram_max:   r ? num(r.ram_max, 1) + ' %' : '—',
        };
        resumenActual = r;
        maxDiscoDelPeriodo();
        Object.keys(txt).forEach(function (k) {
            var el = raiz.querySelector('[data-tel-res="' + k + '"]');
            if (el) {
                el.textContent = txt[k];
                el.classList.toggle('text-danger', k === 'temp_max' && !!r && r.temp_max >= UMBRAL);
            }
        });
    }

    /**
     * "Máximo en uso del período" debajo del disco, sólo si dice algo distinto
     * del valor actual: el disco cambia poco y repetir el mismo número no aporta.
     */
    var resumenActual = null, discoActualGb = null;
    function maxDiscoDelPeriodo() {
        var cont = raiz.querySelector('[data-tel-disco-max]');
        if (!cont) return;
        var r = resumenActual;
        var valor = r ? (discoTotalGb ? enUsoGb(r.disco_min) : r.disco_min / 1024) : null;
        cont.hidden = valor === null || discoActualGb === null || Math.abs(valor - discoActualGb) < 0.1;
        cont.querySelector('strong').textContent = valor === null ? '—' : num(valor, 1) + ' GB';
    }

    function pintar(d, conservarVentana) {
        var vacio = !d.serie.length;
        raiz.querySelector('[data-tel-vacio]').hidden = !vacio;
        raiz.querySelector('[data-tel-graficos]').hidden = vacio;
        resumen(d.resumenes[rango]);
        if (d.ultima && !vivoActivo) {
            indicadores(d.ultima.temp, d.ultima.ram, d.ultima.disco_gb, d.ultima.disco_total_gb);
            raiz.querySelector('[data-tel-ultima]').textContent = 'Última lectura guardada: ' + d.ultima.cuando + '.';
        }
        if (vacio || !conservarVentana) return;
        // Refresco: datos nuevos sin perder adónde se había arrastrado el gráfico.
        var g = graf.temp.w.globals, min = g.minX, max = g.maxX, alFinal = max >= Date.now() - 3 * 3600e3;
        var s = series(d.serie);
        graf.temp.updateSeries(s.temp, false);
        graf.ram.updateSeries(s.ram, false);
        graf.disco.updateSeries(s.disco, false);
        ajustando = true;
        LISTA.forEach(function (c) { c.zoomX(min, alFinal ? Date.now() : max); });
        ajustando = false;
    }
    pintar(datos, false);

    // ── Cambio de rango y refresco ──────────────────────────────────────────
    var cargando = raiz.querySelector('[data-tel-cargando]');
    var historico = raiz.querySelector('[data-tel-historico]');

    raiz.querySelectorAll('[data-tel-rango]').forEach(function (b) {
        b.addEventListener('click', function () {
            rango = b.dataset.telRango;
            raiz.querySelectorAll('[data-tel-rango]').forEach(function (o) {
                var sel = o === b;
                o.classList.toggle('unraf-btn-institucion', sel);
                o.classList.toggle('unraf-btn-light', !sel);
                o.setAttribute('aria-pressed', sel ? 'true' : 'false');
            });
            try { history.replaceState(null, '', '?rango=' + rango); } catch (e) { /* sin historial */ }
            cargando.hidden = false;
            historico.classList.add('is-cargando');
            setTimeout(function () {
                resumen(datos.resumenes[rango]);
                mostrarVentana(rango, true);
                cargando.hidden = true;
                historico.classList.remove('is-cargando');
            }, CARGA_MIN_MS);
        });
    });

    setInterval(function () {
        if (document.hidden) return;
        fetch(raiz.dataset.urlDatos + '?rango=' + encodeURIComponent(rango),
              { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (d) { datos = d; pintar(d, true); })
            .catch(function () { /* sin red: queda lo que había; el próximo refresco reintenta */ });
    }, REFRESCO_MS);

    // ── En vivo ─────────────────────────────────────────────────────────────
    var btnVivo = raiz.querySelector('[data-tel-vivo-btn]');
    var panelVivo = raiz.querySelector('[data-tel-vivo-panel]');
    if (btnVivo && raiz.dataset.mqttUrl && typeof window.mqtt !== 'undefined') {
        var textoVivo = btnVivo.querySelector('[data-tel-vivo-texto]');
        var pausado = false, renovar = null, sinDatos = null, ultimaMuestra = 0;
        var puntos = { temp: [], ram: [] };

        var chartVivo = function (sel, nombre, color, unidad) {
            var c = new ApexCharts(raiz.querySelector(sel), {
                series: [{ name: nombre, data: [] }],
                chart: { type: 'line', height: 220, fontFamily: 'inherit', toolbar: { show: false }, zoom: { enabled: false },
                         // Sin animación: con una lectura por segundo, la de entrada (la línea
                         // que crece desde cero) se veía como un pico falso.
                         animations: { enabled: false } },
                colors: [color], stroke: { curve: 'smooth', width: 2.5 }, dataLabels: { enabled: false },
                grid: { borderColor: '#e9ecef', strokeDashArray: 3 },
                xaxis: { type: 'datetime', range: VIVO_PUNTOS * 1000, labels: { datetimeUTC: false, format: 'HH:mm:ss', style: { colors: GRIS } } },
                yaxis: { min: 0, max: 100, tickAmount: 5, labels: { formatter: function (v) { return Math.round(v) + ' ' + unidad; } } },
                title: { text: nombre, style: { fontSize: '13px', fontWeight: 600 } },
                tooltip: { x: { format: 'HH:mm:ss' }, y: { formatter: function (v) { return num(v, 1) + ' ' + unidad; } } },
                legend: { show: false },
                annotations: unidad === '°C' ? { yaxis: [{ y: UMBRAL, borderColor: ROJO, strokeDashArray: 6 }] } : {}
            });
            return c;
        };
        // Se dibujan recién cuando el panel se muestra (primera lectura): un
        // ApexCharts dibujado dentro de un contenedor oculto calcula mal el
        // ancho y la escala, y la línea quedaba pegada a cero.
        var cTemp = chartVivo('[data-tel-chart="vivo_temp"]', 'Temperatura', AMBAR, '°C');
        var cRam  = chartVivo('[data-tel-chart="vivo_ram"]', 'RAM', AZUL, '%');
        var vivosDibujados = false;

        var estado = function (texto, clase) {
            textoVivo.textContent = texto;
            btnVivo.classList.remove('is-vivo', 'is-pausado', 'is-sin-datos');
            if (clase) btnVivo.classList.add(clase);
        };

        var pedirVivo = function () {
            var token = document.querySelector('meta[name="spui-csrf-token"]');
            fetch(raiz.dataset.urlVivo, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Csrf-Token': token ? token.content : '' }
            }).catch(function () { /* se reintenta en la próxima renovación */ });
            clearTimeout(sinDatos);
            sinDatos = setTimeout(function () {
                if (Date.now() - ultimaMuestra > SIN_DATOS_VIVO_MS) {
                    vivoActivo = false;
                    estado('Sin datos en vivo', 'is-sin-datos');
                    btnVivo.title = 'El reproductor no está mandando datos en vivo: puede estar apagado o desconectado. Se sigue intentando.';
                }
            }, SIN_DATOS_VIVO_MS);
        };

        var empezar = function () {
            if (pausado || document.hidden || renovar) return;
            pedirVivo();
            renovar = setInterval(pedirVivo, VIVO_SEG * 500);
        };
        var frenar = function () {
            clearInterval(renovar); renovar = null;
            clearTimeout(sinDatos);
        };

        var cliente = window.mqtt.connect(raiz.dataset.mqttUrl, {
            username: raiz.dataset.mqttUsuario, password: raiz.dataset.mqttPassword,
            clientId: 'spui-web-tel-' + Math.random().toString(16).slice(2, 10),
            connectTimeout: 5000, reconnectPeriod: 5000
        });
        cliente.on('connect', function () {
            cliente.subscribe(raiz.dataset.mqttTopic);
            if (!pausado) { estado('Esperando datos…'); empezar(); }
        });
        cliente.on('message', function (_t, mensaje) {
            if (pausado) return;
            var m;
            try { m = JSON.parse(mensaje.toString()); } catch (e) { return; }
            ultimaMuestra = Date.now();
            vivoActivo = true;
            estado('En vivo', 'is-vivo');
            btnVivo.title = 'Temperatura y RAM actualizadas cada segundo. Clic para pausar.';
            var t = m.ts ? m.ts * 1000 : Date.now();
            if (m.temperatura_soc_celsius !== null && m.temperatura_soc_celsius !== undefined) puntos.temp.push([t, m.temperatura_soc_celsius]);
            if (m.uso_ram_porcentaje !== null && m.uso_ram_porcentaje !== undefined) puntos.ram.push([t, m.uso_ram_porcentaje]);
            puntos.temp = puntos.temp.slice(-VIVO_PUNTOS);
            puntos.ram = puntos.ram.slice(-VIVO_PUNTOS);
            var libre = m.espacio_disco_libre_mb !== undefined ? m.espacio_disco_libre_mb / 1024 : null;
            var total = m.espacio_disco_total_mb ? m.espacio_disco_total_mb / 1024 : null;
            indicadores(m.temperatura_soc_celsius, m.uso_ram_porcentaje, libre, total);
            raiz.querySelector('[data-tel-ultima]').textContent = 'En vivo · ' + new Date(t).toLocaleTimeString('es-AR') + '.';
            // Con un solo punto ApexCharts dibuja una raya vertical desde el
            // eje: el panel se muestra desde la segunda lectura (1 s después).
            if (puntos.temp.length < 2 && puntos.ram.length < 2) return;
            panelVivo.hidden = false;
            if (!vivosDibujados) { vivosDibujados = true; cTemp.render(); cRam.render(); }
            cTemp.updateSeries([{ name: 'Temperatura', data: puntos.temp }]);
            cRam.updateSeries([{ name: 'RAM', data: puntos.ram }]);
        });
        cliente.on('offline', function () { if (!pausado) estado('Reconectando…', 'is-sin-datos'); });

        btnVivo.addEventListener('click', function () {
            pausado = !pausado;
            btnVivo.setAttribute('aria-pressed', pausado ? 'false' : 'true');
            if (pausado) {
                frenar();
                vivoActivo = false;
                estado('En vivo pausado', 'is-pausado');
                btnVivo.title = 'Clic para volver a ver los datos en vivo.';
            } else {
                estado('Esperando datos…');
                empezar();
            }
        });
        // Pestaña oculta: no se renueva el pedido, así la Pi deja de publicar
        // cada segundo para alguien que no está mirando.
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) frenar(); else empezar();
        });
    } else if (btnVivo) {
        btnVivo.querySelector('[data-tel-vivo-texto]').textContent = 'En vivo no disponible';
        btnVivo.classList.add('is-sin-datos');
        btnVivo.disabled = true;
    }

    // ── Exportar (el formulario llega en el modal) ──────────────────────────
    // Fase de burbujeo: validacion-vivo.js revisa los campos antes, en captura,
    // y si algo está mal corta el envío ahí.
    document.addEventListener('submit', function (e) {
        var form = e.target.closest && e.target.closest('form[data-tel-exportar]');
        if (!form) return;
        e.preventDefault();
        var btn = form.querySelector('[data-tel-exportar-btn]'), general = form.querySelector('[data-tel-exportar-error]');
        var campos = form.querySelectorAll('[name="desde"], [name="hasta"]');
        general.hidden = true;
        campos.forEach(function (c) { window.spui.limpiarErrorDeCampo(c); });
        var textoBtn = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Generando…';
        var formato = (form.querySelector('[name="formato"]:checked') || {}).value || 'xlsx';
        var url = form.action + '?formato=' + encodeURIComponent(formato)
            + '&desde=' + encodeURIComponent(form.querySelector('[name="desde"]').value)
            + '&hasta=' + encodeURIComponent(form.querySelector('[name="hasta"]').value);
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) {
                if (r.status === 422) {
                    return r.json().then(function (j) {
                        Object.keys(j.errores || {}).forEach(function (k) {
                            var c = form.querySelector('[name="' + k + '"]');
                            if (c) window.spui.errorDeCampo(c, j.errores[k]);
                        });
                        if (j.mensaje) { general.textContent = j.mensaje; general.hidden = false; }
                    });
                }
                if (!r.ok) throw new Error('HTTP ' + r.status);
                var disp = r.headers.get('Content-Disposition') || '';
                var nombre = (disp.match(/filename="?([^";]+)"?/) || [])[1] || ('telemetria.' + formato);
                return r.blob().then(function (b) {
                    var a = document.createElement('a');
                    a.href = URL.createObjectURL(b);
                    a.download = nombre;
                    document.body.appendChild(a);
                    a.click();
                    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
                    if (window.spui.closeModal) window.spui.closeModal();
                });
            })
            .catch(function () {
                general.textContent = 'No se pudo generar el archivo. Revisá la conexión y probá de nuevo.';
                general.hidden = false;
            })
            .then(function () { btn.disabled = false; btn.textContent = textoBtn; });
    });
}());
