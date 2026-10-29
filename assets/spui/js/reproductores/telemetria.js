// Telemetría de un reproductor (templates/reproductores/telemetria.html.twig).
//
// Con ECharts (canvas) y no ApexCharts (SVG): ApexCharts redibuja el gráfico
// entero en cada movimiento y el arrastre se sentía trabado (29/10/2026).
//
//   - Histórico por hora: tres gráficos conectados (se mueven y muestran el
//     tooltip juntos). La página trae los últimos 60 días; 24 h / 7 d / 30 d
//     eligen la ventana. Arrastrar mueve la vista, la rueda hace zoom centrado
//     en el puntero, y los botones + / − / volver hacen lo mismo. Todo pasa por
//     Vista.irA(), que anima la transición: nunca hay saltos. Tope en "ahora".
//   - Indicadores circulares: temperatura, RAM y disco en uso.
//   - En vivo: suscrito por WebSocket a spui/telemetria/vivo/{id}. Mientras la
//     pestaña está visible se le pide a la Pi (POST /telemetria/vivo) que
//     publique cada 1 s, y se renueva a la mitad del plazo. Guarda los últimos
//     10 minutos: muestra los 2 últimos deslizándose solos, y se puede
//     arrastrar o hacer zoom hacia atrás; "volver" retoma el seguimiento.
//   - Exportar: el modal (_telemetria_exportar.html.twig) se envía desde acá;
//     lo que vuelve es un archivo, o un 422 con los errores por campo.
(function () {
    'use strict';

    var raiz = document.getElementById('spui-telemetria');
    if (!raiz || typeof window.echarts === 'undefined') return;
    var echarts = window.echarts;

    var AZUL = '#3387EF', AMBAR = '#C77F0A', ROJO = '#FF223D', VERDE = '#4A9930', VIOLETA = '#7A5AF8', GRIS = '#6c757d';
    var UMBRAL = parseFloat(raiz.dataset.umbral) || 80;
    var VIVO_SEG = parseInt(raiz.dataset.vivoSeg, 10) || 120;
    var VIVO_GUARDA = 600;            // lecturas en vivo que se conservan: 10 minutos
    var VIVO_VENTANA = 120e3;         // lo que se ve en vivo: 2 minutos
    var SIN_DATOS_VIVO_MS = 15000;    // pedido hecho y nada llegó: la Pi no responde
    var REFRESCO_MS = 60000;
    var HORAS = { '24h': 24, '7d': 168, '30d': 720 };
    var MIN_VENTANA_HIST = 3 * 3600e3; // zoom máximo del histórico: 3 horas
    var MIN_VENTANA_VIVO = 15e3;       // zoom máximo en vivo: 15 segundos

    var datos = JSON.parse(raiz.querySelector('[data-tel-datos]').textContent);
    var rango = datos.rango;
    var vivoActivo = false;           // llegó al menos una muestra en vivo y no se pausó
    // Capacidad del disco (GB). Se cuenta lo EN USO contra ella, no lo libre
    // (pedido de Mateo, 29/10/2026). Null con clientes de la Pi anteriores a
    // esa fecha, que no la mandan: ahí se sigue mostrando lo libre, aclarado.
    var discoTotalGb = datos.ultima ? datos.ultima.disco_total_gb : null;
    function enUsoGb(libreMb) { return discoTotalGb ? Math.max(0, discoTotalGb - libreMb / 1024) : null; }

    // ── Idioma ──────────────────────────────────────────────────────────────
    echarts.registerLocale('ES', {
        time: {
            month: ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'],
            monthAbbr: ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'],
            dayOfWeek: ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'],
            dayOfWeekAbbr: ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb']
        },
        legend: { selector: { all: 'Todas', inverse: 'Invertir' } }
    });

    function num(v, dec) { return (v === null || v === undefined || isNaN(v)) ? '—' : Number(v).toFixed(dec).replace('.', ','); }
    function colorTemp(t) { return t >= UMBRAL ? ROJO : (t >= UMBRAL * 0.85 ? AMBAR : VERDE); }
    function colorPorc(r) { return r >= 90 ? ROJO : (r >= 75 ? AMBAR : AZUL); }
    function dd(n) { return String(n).padStart(2, '0'); }
    function fecha(t, conSegundos) {
        var d = new Date(t);
        return dd(d.getDate()) + '/' + dd(d.getMonth() + 1) + '/' + d.getFullYear() + ' ' + dd(d.getHours()) + ':' + dd(d.getMinutes())
            + (conSegundos ? ':' + dd(d.getSeconds()) : '');
    }
    /** "2026-10-29 08:00" → milisegundos, en hora local (como lo guarda el CMS). */
    function ms(periodo) {
        var p = periodo.split(/[- :]/);
        return new Date(+p[0], +p[1] - 1, +p[2], +p[3], +(p[4] || 0)).getTime();
    }
    function rgba(hex, a) {
        var n = parseInt(hex.slice(1), 16);
        return 'rgba(' + (n >> 16) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
    }
    var graficos = [];
    function crear(el) {
        var c = echarts.init(el, null, { locale: 'ES', renderer: 'canvas' });
        graficos.push(c);
        return c;
    }
    window.addEventListener('resize', function () { graficos.forEach(function (c) { c.resize(); }); });

    // ── Vista: la ventana visible de uno o varios gráficos conectados ───────
    //
    // Arrastrar lo resuelve el dataZoom "inside" de ECharts (canvas: fluido).
    // La rueda y los botones pasan por irA(), que anima desde donde se está
    // hasta donde se pidió; varios giros de rueda seguidos se encadenan sin
    // cortes porque cada uno parte de la posición animada del momento.
    function Vista(lista, limites, minVentana) {
        this.lista = lista;
        this.limites = limites;
        this.minVentana = minVentana;
        this.actual = null;
        this.objetivo = null;
        this.animacion = null;
        this.propio = false;
        this.alMover = null;
        var self = this;
        lista[0].on('datazoom', function () {
            if (self.propio) return;
            var v = self.leer();
            cancelAnimationFrame(self.animacion);
            self.animacion = null;
            self.actual = v;
            self.objetivo = v;
            if (self.alMover) self.alMover(v, true);
        });
    }
    /** Dónde está la vista ahora, según los límites que tiene el eje dibujado. */
    Vista.prototype.leer = function () {
        var o = this.lista[0].getOption(), dz = o.dataZoom[0], x = o.xAxis[0];
        return { s: x.min + (x.max - x.min) * dz.start / 100, e: x.min + (x.max - x.min) * dz.end / 100 };
    };
    Vista.prototype.acotar = function (s, e) {
        var l = this.limites(), span = Math.max(this.minVentana, Math.min(e - s, l.max - l.min));
        if (s < l.min) { s = l.min; }
        if (s + span > l.max) { s = l.max - span; }
        s = Math.max(l.min, s);
        return { s: s, e: s + span };
    };
    Vista.prototype.aplicar = function (v) {
        this.propio = true;
        this.lista[0].dispatchAction({ type: 'dataZoom', dataZoomIndex: 0, startValue: v.s, endValue: v.e });
        this.propio = false;
        this.actual = v;
        if (this.alMover) this.alMover(v, false);
    };
    /** Va hasta [s, e] en `dur` ms (0 = de una), con una curva que frena al llegar. */
    Vista.prototype.irA = function (s, e, dur, lineal) {
        var destino = this.acotar(s, e), self = this;
        this.objetivo = destino;
        cancelAnimationFrame(this.animacion);
        var desde = this.actual || this.leer();
        if (!dur) { this.animacion = null; this.aplicar(destino); return; }
        var t0 = performance.now();
        function paso(t) {
            var k = Math.min(1, (t - t0) / dur), f = lineal ? k : 1 - Math.pow(1 - k, 3);
            self.animacion = k < 1 ? requestAnimationFrame(paso) : null;
            self.aplicar({ s: desde.s + (destino.s - desde.s) * f, e: desde.e + (destino.e - desde.e) * f });
        }
        this.animacion = requestAnimationFrame(paso);
    };
    /** Zoom por un factor (<1 acerca), dejando quieto el instante `ancla`. */
    Vista.prototype.zoom = function (factor, ancla, dur) {
        var o = this.objetivo || this.leer(), span = o.e - o.s;
        if (ancla === undefined || ancla === null) ancla = (o.s + o.e) / 2;
        var nuevo = span * factor, p = (ancla - o.s) / span;
        var s = ancla - p * nuevo;
        this.irA(s, s + nuevo, dur === undefined ? 260 : dur);
    };
    /**
     * Rueda del mouse sobre el gráfico: zoom suave centrado en el puntero. En
     * fase de captura: el lienzo de ECharts frena el evento de la rueda y, en
     * burbujeo, este código no se enteraba.
     */
    Vista.prototype.conRueda = function (grafico) {
        var self = this;
        grafico.getDom().addEventListener('wheel', function (e) {
            e.preventDefault();
            var delta = e.deltaY * (e.deltaMode === 1 ? 30 : 1);
            var r = grafico.getDom().getBoundingClientRect();
            var ancla = grafico.convertFromPixel({ xAxisIndex: 0 }, [e.clientX - r.left, e.clientY - r.top]);
            ancla = Array.isArray(ancla) ? ancla[0] : ancla;
            self.zoom(Math.exp(delta * 0.0013), isFinite(ancla) ? ancla : null, 220);
        }, { passive: false, capture: true });
    };

    /** Botones de una barra (data-tel-herramientas) para una vista y sus gráficos. */
    function herramientas(barra, vista, alVolver) {
        if (!barra) return;
        barra.addEventListener('click', function (e) {
            var b = e.target.closest('[data-tel-zoom]');
            if (!b) return;
            var accion = b.dataset.telZoom;
            if (accion === 'mas') vista.zoom(0.6);
            else if (accion === 'menos') vista.zoom(1 / 0.6);
            else if (accion === 'inicio') alVolver();
            else if (accion === 'png') {
                barra.closest('.unraf-card').querySelectorAll('[data-tel-chart]').forEach(function (el, i) {
                    var c = echarts.getInstanceByDom(el);
                    var a = document.createElement('a');
                    a.href = c.getDataURL({ pixelRatio: 2, backgroundColor: '#fff' });
                    a.download = 'telemetria-' + (raiz.dataset.hostname || '') + '-' + el.dataset.telChart + '.png';
                    setTimeout(function () { a.click(); }, i * 300);
                });
            }
        });
    }

    // ── Opciones comunes de un gráfico de líneas en el tiempo ───────────────
    function tooltipHora(conSegundos, formatoValor) {
        return {
            trigger: 'axis',
            backgroundColor: 'rgba(255,255,255,.97)', borderColor: '#dee2e6', borderWidth: 1,
            textStyle: { color: '#2b3a4a', fontSize: 12 },
            axisPointer: { type: 'line', lineStyle: { color: '#adb5bd' } },
            formatter: function (ps) {
                if (!ps.length) return '';
                var h = '<div style="font-weight:600;margin-bottom:2px">' + fecha(ps[0].value[0], conSegundos) + '</div>';
                ps.forEach(function (p) {
                    if (p.value[1] === null || p.value[1] === undefined) return;
                    h += '<div>' + p.marker + p.seriesName + ': <strong>' + formatoValor(p.value[1]) + '</strong></div>';
                });
                return h;
            }
        };
    }
    function opcion(o) {
        return {
            animationDuration: 900, animationEasing: 'cubicOut', animationDurationUpdate: 300,
            grid: { left: 62, right: 16, top: o.leyenda ? 36 : 18, bottom: 28 },
            tooltip: tooltipHora(o.segundos, o.formato),
            legend: o.leyenda ? { top: 0, left: 0, icon: 'circle', itemWidth: 10, itemHeight: 10, textStyle: { color: '#495057' } } : { show: false },
            xAxis: {
                type: 'time', min: o.min, max: o.max,
                axisLine: { lineStyle: { color: '#ced4da' } }, axisTick: { lineStyle: { color: '#ced4da' } },
                splitLine: { show: false },
                axisLabel: { color: GRIS, hideOverlap: true, formatter: {
                    year: '{yyyy}', month: '{MMM}', day: '{d} {MMM}', hour: '{HH}:{mm}', minute: '{HH}:{mm}',
                    second: '{HH}:{mm}:{ss}', millisecond: '{HH}:{mm}:{ss}', none: '{HH}:{mm}' } }
            },
            yAxis: {
                type: 'value', min: 0, max: o.yMax, interval: o.yMax / (o.marcas || 5),
                axisLabel: { color: GRIS, formatter: o.eje },
                splitLine: { lineStyle: { color: '#e9ecef', type: 'dashed' } }
            },
            dataZoom: [{
                type: 'inside', xAxisIndex: 0, filterMode: 'none', throttle: 0,
                zoomOnMouseWheel: false, moveOnMouseWheel: false, moveOnMouseMove: true, preventDefaultMouseMove: true,
                startValue: o.desde, endValue: o.hasta, minValueSpan: o.minVentana
            }],
            series: o.series
        };
    }
    function linea(nombre, color, datosSerie, conArea, extra) {
        return Object.assign({
            type: 'line', name: nombre, data: datosSerie, smooth: 0.35, showSymbol: false, symbol: 'circle', symbolSize: 7,
            sampling: 'lttb', lineStyle: { width: 2.5, color: color }, itemStyle: { color: color },
            areaStyle: conArea ? { color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                { offset: 0, color: rgba(color, 0.32) }, { offset: 1, color: rgba(color, 0.02) }]) } : undefined
        }, extra || {});
    }
    function lineaLimite(valor, texto, abajo) {
        return {
            silent: true, symbol: 'none', animation: false,
            lineStyle: { color: ROJO, type: 'dashed', width: 1.5 },
            label: { formatter: texto, position: abajo ? 'insideStartBottom' : 'insideStartTop', color: '#fff', backgroundColor: ROJO, padding: [2, 6], borderRadius: 3, fontSize: 11 },
            data: [{ yAxis: valor }]
        };
    }

    // ── Histórico ───────────────────────────────────────────────────────────
    function limitesHist() {
        var ahora = Date.now();
        var primero = datos.serie.length ? ms(datos.serie[0].periodo) : ahora - HORAS['30d'] * 3600e3;
        return { min: Math.min(primero, ahora - MIN_VENTANA_HIST), max: ahora };
    }
    function ventana(r) {
        var l = limitesHist();
        return { s: Math.max(l.min, l.max - HORAS[r] * 3600e3), e: l.max };
    }
    function seriesHist(serie) {
        return {
            temp: [serie.map(function (p) { return [ms(p.periodo), p.temp_prom]; }),
                   serie.map(function (p) { return [ms(p.periodo), p.temp_max]; })],
            ram: serie.map(function (p) { return [ms(p.periodo), p.ram_prom]; }),
            // Por hora, el máximo en uso = capacidad − mínimo libre de esa hora.
            disco: serie.map(function (p) {
                var gb = discoTotalGb ? enUsoGb(p.disco_min) : p.disco_min / 1024;
                return [ms(p.periodo), Math.round(gb * 10) / 10];
            })
        };
    }
    function maxTemp(serie) {
        var m = serie.reduce(function (a, p) { return Math.max(a, p.temp_max || 0); }, 0);
        return Math.max(100, Math.ceil(m / 20) * 20);
    }
    /** Tope del eje del disco: la capacidad, que es el límite real; sin ella, un número redondo. */
    function maxDisco(valores) {
        if (discoTotalGb) return discoTotalGb;
        var m = valores.reduce(function (a, p) { return Math.max(a, p[1] || 0); }, 0);
        var pasos = [0.5, 1, 2, 2.5, 5, 10, 20, 25, 40, 50, 75, 100, 150, 200, 250, 500];
        return (pasos.filter(function (p) { return p * 4 >= m * 1.15; })[0] || 500) * 4;
    }
    function ejeGb(v) { return num(v, (v === 0 || v >= 10) ? 0 : 1) + ' GB'; }
    function valorDisco(v) { return num(v, 1) + ' GB' + (discoTotalGb ? ' (' + num(v / discoTotalGb * 100, 0) + ' %)' : ''); }

    var hist = {};
    var graficosHist = raiz.querySelector('[data-tel-graficos]');
    (function armarHistorico() {
        var l = limitesHist(), v = ventana(rango), s = seriesHist(datos.serie);
        var comun = { min: l.min, max: l.max, desde: v.s, hasta: v.e, minVentana: MIN_VENTANA_HIST };
        hist.temp = crear(raiz.querySelector('[data-tel-chart="temp"]'));
        hist.temp.setOption(opcion(Object.assign({}, comun, {
            leyenda: true, yMax: maxTemp(datos.serie), eje: function (x) { return Math.round(x) + ' °C'; },
            formato: function (x) { return num(x, 1) + ' °C'; },
            series: [linea('Promedio', AZUL, s.temp[0], true, { markLine: lineaLimite(UMBRAL, 'Umbral ' + UMBRAL + ' °C') }),
                     linea('Máxima', AMBAR, s.temp[1], true)]
        })));
        hist.ram = crear(raiz.querySelector('[data-tel-chart="ram"]'));
        hist.ram.setOption(opcion(Object.assign({}, comun, {
            yMax: 100, eje: function (x) { return Math.round(x) + ' %'; }, formato: function (x) { return num(x, 1) + ' %'; },
            series: [linea('RAM promedio', AZUL, s.ram, true)]
        })));
        hist.disco = crear(raiz.querySelector('[data-tel-chart="disco"]'));
        hist.disco.setOption(opcion(Object.assign({}, comun, {
            yMax: maxDisco(s.disco), marcas: 4, eje: ejeGb, formato: valorDisco,
            series: [linea(discoTotalGb ? 'Disco en uso' : 'Disco libre', VIOLETA, s.disco, true,
                discoTotalGb ? { markLine: lineaLimite(discoTotalGb, 'Capacidad ' + num(discoTotalGb, 0) + ' GB', true) } : {})]
        })));
        [hist.temp, hist.ram, hist.disco].forEach(function (c) { c.group = 'spui-tel-historico'; });
        echarts.connect('spui-tel-historico');
    }());

    var vistaHist = new Vista([hist.temp, hist.ram, hist.disco], limitesHist, MIN_VENTANA_HIST);
    [hist.temp, hist.ram, hist.disco].forEach(function (c) { vistaHist.conRueda(c); });
    raiz.querySelectorAll('[data-tel-herramientas="historico"]').forEach(function (b) {
        herramientas(b, vistaHist, function () { var v = ventana(rango); vistaHist.irA(v.s, v.e, 600); });
    });

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
            resumen(datos.resumenes[rango]);
            var v = ventana(rango);
            vistaHist.irA(v.s, v.e, 700);
        });
    });

    /** Datos nuevos (refresco de cada minuto) sin perder adónde se había movido la vista. */
    function actualizarHistorico() {
        var antes = vistaHist.objetivo || vistaHist.leer(), finAntes = antes.e;
        var l = limitesHist(), s = seriesHist(datos.serie), tMax = maxTemp(datos.serie);
        var alFinal = finAntes >= l.max - REFRESCO_MS - 10 * 60e3;
        hist.temp.setOption({ xAxis: { min: l.min, max: l.max }, yAxis: { max: tMax, interval: tMax / 5 },
                              series: [{ data: s.temp[0] }, { data: s.temp[1] }] });
        hist.ram.setOption({ xAxis: { min: l.min, max: l.max }, series: [{ data: s.ram }] });
        hist.disco.setOption({ xAxis: { min: l.min, max: l.max }, series: [{ data: s.disco }] });
        var span = antes.e - antes.s;
        vistaHist.actual = null;
        if (alFinal) vistaHist.irA(l.max - span, l.max, 0); else vistaHist.irA(antes.s, antes.e, 0);
    }

    // ── Indicadores circulares ──────────────────────────────────────────────
    function gauge(el, color) {
        var c = crear(el);
        c.setOption({ series: [{
            type: 'gauge', startAngle: 225, endAngle: -45, min: 0, max: 100, radius: '94%', center: ['50%', '56%'],
            progress: { show: true, roundCap: true, width: 16, itemStyle: { color: color } },
            axisLine: { roundCap: true, lineStyle: { width: 16, color: [[1, '#eef1f4']] } },
            pointer: { show: false }, axisTick: { show: false }, splitLine: { show: false }, axisLabel: { show: false },
            anchor: { show: false }, title: { show: false },
            detail: { offsetCenter: [0, '6%'], fontSize: 30, fontWeight: 700, color: '#2b3a4a', formatter: '—' },
            data: [{ value: 0 }],
            animationDuration: 1100, animationDurationUpdate: 500, animationEasing: 'cubicOut', animationEasingUpdate: 'cubicOut'
        }] });
        return c;
    }
    var gTemp  = gauge(raiz.querySelector('[data-tel-gauge="temp"]'), VERDE);
    var gRam   = gauge(raiz.querySelector('[data-tel-gauge="ram"]'), AZUL);
    var gDisco = gauge(raiz.querySelector('[data-tel-gauge="disco"]'), VIOLETA);

    function ponerGauge(g, valor, texto, color) {
        g.setOption({ series: [{
            progress: { itemStyle: { color: color } },
            detail: { formatter: texto.replace(/[{}]/g, '') },
            data: [{ value: Math.max(0, Math.min(100, valor || 0)) }]
        }] });
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

    function indicadores(temp, ram, discoLibreGb, totalGb) {
        if (temp !== null && temp !== undefined) ponerGauge(gTemp, temp, num(temp, 1) + ' °C', colorTemp(temp));
        if (ram !== null && ram !== undefined) ponerGauge(gRam, ram, num(ram, 1) + ' %', colorPorc(ram));
        if (totalGb) discoTotalGb = totalGb;
        if (discoLibreGb !== null && discoLibreGb !== undefined) {
            var etiqueta = raiz.querySelector('[data-tel-disco-etq]');
            if (discoTotalGb) {
                var usoGb = Math.max(0, discoTotalGb - discoLibreGb), uso = usoGb / discoTotalGb * 100;
                ponerGauge(gDisco, uso, num(uso, 0) + ' %', colorPorc(uso));
                etiqueta.textContent = 'En uso';
                raiz.querySelector('[data-tel-numero="disco"]').textContent = num(usoGb, 1) + ' GB';
                raiz.querySelector('[data-tel-numero="disco_total"]').textContent = num(discoTotalGb, 0) + ' GB';
            } else {
                // Clientes anteriores al 29/10/2026 no mandan el tamaño del disco.
                ponerGauge(gDisco, 0, num(discoLibreGb, 0) + ' GB', VIOLETA);
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
            ram_max:   r ? num(r.ram_max, 1) + ' %' : '—'
        };
        Object.keys(txt).forEach(function (k) {
            var el = raiz.querySelector('[data-tel-res="' + k + '"]');
            if (el) {
                el.textContent = txt[k];
                el.classList.toggle('text-danger', k === 'temp_max' && !!r && r.temp_max >= UMBRAL);
            }
        });
        resumenActual = r;
        maxDiscoDelPeriodo();
    }

    function pintar(d) {
        var vacio = !d.serie.length;
        raiz.querySelector('[data-tel-vacio]').hidden = !vacio;
        graficosHist.hidden = vacio;
        resumen(d.resumenes[rango]);
        if (d.ultima && !vivoActivo) {
            indicadores(d.ultima.temp, d.ultima.ram, d.ultima.disco_gb, d.ultima.disco_total_gb);
            raiz.querySelector('[data-tel-ultima]').textContent = 'Última lectura guardada: ' + d.ultima.cuando + '.';
        }
    }
    pintar(datos);

    setInterval(function () {
        if (document.hidden) return;
        fetch(raiz.dataset.urlDatos + '?rango=' + encodeURIComponent(rango),
              { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (d) { datos = d; pintar(d); actualizarHistorico(); })
            .catch(function () { /* sin red: queda lo que había; el próximo refresco reintenta */ });
    }, REFRESCO_MS);

    // ── En vivo ─────────────────────────────────────────────────────────────
    var btnVivo = raiz.querySelector('[data-tel-vivo-btn]');
    var panelVivo = raiz.querySelector('[data-tel-vivo-panel]');
    if (btnVivo && panelVivo && raiz.dataset.mqttUrl && typeof window.mqtt !== 'undefined') {
        var textoVivo = btnVivo.querySelector('[data-tel-vivo-texto]');
        var espera = panelVivo.querySelector('[data-tel-vivo-espera]');
        var esperaTexto = panelVivo.querySelector('[data-tel-vivo-espera-texto]');
        var esperaSpinner = panelVivo.querySelector('[data-tel-vivo-spinner]');
        var pausado = false, renovar = null, sinDatos = null, ultimaMuestra = 0, siguiendo = true;
        var puntos = { temp: [], ram: [] };

        var limitesVivo = function () {
            var ult = puntos.temp.length ? puntos.temp[puntos.temp.length - 1][0] : Date.now();
            var pri = puntos.temp.length ? puntos.temp[0][0] : ult - VIVO_VENTANA;
            return { min: Math.min(pri, ult - VIVO_VENTANA), max: ult };
        };
        var armarVivo = function (sel, nombre, color, unidad, limite) {
            var c = crear(panelVivo.querySelector(sel)), l = limitesVivo();
            var o = opcion({
                min: l.min, max: l.max, desde: l.max - VIVO_VENTANA, hasta: l.max, minVentana: MIN_VENTANA_VIVO, segundos: true,
                yMax: 100, eje: function (x) { return Math.round(x) + ' ' + unidad; }, formato: function (x) { return num(x, 1) + ' ' + unidad; },
                series: [linea(nombre, color, [], false, limite ? { markLine: lineaLimite(limite, 'Umbral ' + limite + ' °C') } : {})]
            });
            // Los datos llegan cada segundo: sin animación de datos; el
            // deslizamiento lo hace la vista (Vista.irA, lineal, 1 s).
            o.animation = false;
            o.grid.top = 28;
            // Marcas cada 15 s como mínimo: con 5 s las horas se pisaban.
            o.xAxis.minInterval = 15e3;
            o.xAxis.splitNumber = 4;
            o.title = { text: nombre, left: 0, top: 0, textStyle: { fontSize: 13, fontWeight: 600, color: '#2b3a4a' } };
            c.setOption(o);
            c.group = 'spui-tel-vivo';
            return c;
        };
        var cTemp = armarVivo('[data-tel-chart="vivo_temp"]', 'Temperatura', AMBAR, '°C', UMBRAL);
        var cRam  = armarVivo('[data-tel-chart="vivo_ram"]', 'RAM', AZUL, '%', null);
        echarts.connect('spui-tel-vivo');
        var vistaVivo = new Vista([cTemp, cRam], limitesVivo, MIN_VENTANA_VIVO);
        [cTemp, cRam].forEach(function (c) { vistaVivo.conRueda(c); });
        // Si el usuario lleva la vista hacia atrás deja de seguir los datos
        // nuevos; los vuelve a seguir si la lleva de nuevo hasta el final.
        vistaVivo.alMover = function (v, delUsuario) {
            if (delUsuario) siguiendo = v.e >= limitesVivo().max - 1500;
        };
        var zoomOriginal = vistaVivo.zoom;
        vistaVivo.zoom = function (f, ancla, dur) {
            zoomOriginal.call(vistaVivo, f, siguiendo ? limitesVivo().max : ancla, dur);
        };
        // Mientras se aprieta el mouse (o el dedo) sobre un gráfico en vivo, la
        // vista no se desliza sola: el deslizamiento corre cuadro a cuadro y
        // pisaba el arrastre, que nunca llegaba a moverse. Al soltar, si quedó
        // al final vuelve a seguir los datos; si no, se queda donde se la dejó.
        var apretado = false;
        [cTemp, cRam].forEach(function (c) {
            c.getDom().addEventListener('pointerdown', function () {
                apretado = true;
                siguiendo = false;
                cancelAnimationFrame(vistaVivo.animacion);
                vistaVivo.animacion = null;
            }, true);
        });
        document.addEventListener('pointerup', function () {
            if (!apretado) return;
            apretado = false;
            var v = vistaVivo.leer();
            vistaVivo.actual = v;
            vistaVivo.objetivo = v;
            siguiendo = v.e >= limitesVivo().max - 1500;
        });
        var volverAlFinal = function () {
            siguiendo = true;
            var l = limitesVivo();
            vistaVivo.irA(l.max - VIVO_VENTANA, l.max, 500);
        };
        herramientas(panelVivo.querySelector('[data-tel-herramientas="vivo"]'), vistaVivo, volverAlFinal);

        var mostrarEspera = function (texto, conSpinner) {
            espera.hidden = !texto;
            if (texto) esperaTexto.textContent = texto;
            esperaSpinner.hidden = !conSpinner;
        };
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
                    if (puntos.temp.length < 2) mostrarEspera('El reproductor no está mandando datos en vivo: puede estar apagado o desconectado. Se sigue intentando.', false);
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
            puntos.temp = puntos.temp.slice(-VIVO_GUARDA);
            puntos.ram = puntos.ram.slice(-VIVO_GUARDA);
            var libre = m.espacio_disco_libre_mb !== undefined ? m.espacio_disco_libre_mb / 1024 : null;
            var total = m.espacio_disco_total_mb ? m.espacio_disco_total_mb / 1024 : null;
            indicadores(m.temperatura_soc_celsius, m.uso_ram_porcentaje, libre, total);
            raiz.querySelector('[data-tel-ultima]').textContent = 'En vivo · ' + new Date(t).toLocaleTimeString('es-AR') + '.';
            if (puntos.temp.length < 2) return;   // con un punto no hay línea que mostrar
            mostrarEspera(null);

            // Mientras se arrastra no se toca el gráfico: cambiar los límites del
            // eje correría la vista bajo el puntero. La lectura queda guardada
            // y aparece con la siguiente, al soltar.
            if (apretado) return;
            var antes = vistaVivo.objetivo || vistaVivo.leer(), l = limitesVivo();
            cTemp.setOption({ xAxis: { min: l.min, max: l.max }, series: [{ data: puntos.temp }] });
            cRam.setOption({ xAxis: { min: l.min, max: l.max }, series: [{ data: puntos.ram }] });
            // setOption reubicó la vista según los límites nuevos: se la vuelve
            // a poner donde estaba y, si se está siguiendo, se la desliza un
            // segundo en un segundo, para que se vea continuo.
            vistaVivo.aplicar(antes);
            if (siguiendo) {
                var span = Math.max(MIN_VENTANA_VIVO, antes.e - antes.s);
                vistaVivo.irA(l.max - span, l.max, puntos.temp.length > 2 ? 950 : 0, true);
            }
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
        if (panelVivo) panelVivo.hidden = true;
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
