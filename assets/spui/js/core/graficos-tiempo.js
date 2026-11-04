// Gráficos en el tiempo, comunes a Telemetría y al Dashboard (extraído de
// reproductores/telemetria.js el 30/10/2026, para que los dos se vean y se
// muevan igual: pedido de Mateo).
//
//   - Opciones base (opcion, linea, lineaLimite, tooltipHora): ejes, grilla,
//     tooltip blanco, línea suave con degradé, umbral como etiqueta roja.
//   - Vista: la ventana visible de un gráfico. Arrastrar lo resuelve el
//     dataZoom "inside" de ECharts; la rueda y los botones pasan por irA(),
//     que anima la transición.
//   - herramientas: la barra + / − / volver / imagen de cada gráfico
//     (data-tel-zoom), que actúa sólo sobre su gráfico.
//
// Script clásico: deja todo en window.spui.graficosTiempo. Requiere ECharts.
(function () {
    'use strict';

    if (typeof window.echarts === 'undefined') return;
    var echarts = window.echarts;
    var ROJO = '#FF223D', GRIS = '#6c757d';

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

    // ── Vista: la ventana visible de uno o varios gráficos conectados ───────
    //
    // Arrastrar lo resuelve el dataZoom "inside" de ECharts (canvas: fluido).
    // La rueda y los botones pasan por irA(), que anima desde donde se está
    // hasta donde se pidió; varios giros de rueda seguidos se encadenan sin
    // cortes porque cada uno parte de la posición animada del momento.
    function Vista(grafico, limites, minVentana) {
        this.lista = [grafico];
        this.limites = limites;
        this.minVentana = minVentana;
        this.actual = null;
        this.objetivo = null;
        this.animacion = null;
        this.propio = false;
        this.alMover = null;
        var self = this;
        grafico.on('datazoom', function () {
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

    /** Botones de una barra (data-tel-herramientas): actúan sólo sobre el gráfico de esa vista. */
    function herramientas(barra, vista, alVolver, nombrePng) {
        if (!barra) return;
        barra.addEventListener('click', function (e) {
            var b = e.target.closest('[data-tel-zoom]');
            if (!b) return;
            var accion = b.dataset.telZoom;
            if (accion === 'mas') vista.zoom(0.6);
            else if (accion === 'menos') vista.zoom(1 / 0.6);
            else if (accion === 'inicio') alVolver();
            else if (accion === 'png') {
                var c = vista.lista[0], a = document.createElement('a');
                a.href = c.getDataURL({ pixelRatio: 2, backgroundColor: '#fff' });
                a.download = (nombrePng ? nombrePng(c) : 'grafico') + '.png';
                a.click();
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

    window.spui = window.spui || {};
    window.spui.graficosTiempo = {
        num: num, fecha: fecha, ms: ms, rgba: rgba, Vista: Vista, herramientas: herramientas,
        tooltipHora: tooltipHora, opcion: opcion, linea: linea, lineaLimite: lineaLimite
    };
})();
