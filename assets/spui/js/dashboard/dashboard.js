// Gráficos del Dashboard (29/10/2026): dona del estado de los reproductores y
// temperatura de toda la red en las últimas 24 h.
//
// Los contenedores llevan data-spui-refresh-conservar, así que auto-refresh.js
// no los toca: cada 20 s reemplaza el <script data-dash-datos> con los números
// nuevos y avisa con spui:refrescado, y acá se redibuja con setOption (sin
// destruir el canvas, que es lo que evita el parpadeo).
(function () {
    'use strict';

    if (typeof echarts === 'undefined') { return; }

    var VERDE = '#4A9930', ROJO = '#FF223D', GRIS_CLARO = '#adb5bd', GRIS = '#6c757d';
    // Paleta para distinguir equipos en el gráfico de temperatura.
    var PALETA = ['#3387EF', '#7A5AF8', '#1CA1A7', '#C77F0A', '#D6457A', '#4A9930', '#5B6B7F'];

    var graficos = {};

    function leerDatos() {
        var nodo = document.querySelector('[data-dash-datos]');
        if (!nodo) { return null; }
        try { return JSON.parse(nodo.textContent); } catch (e) { return null; }
    }

    // "2026-10-29 14:00" → milisegundos en hora local.
    function ms(periodo) {
        var p = periodo.split(/[- :]/);
        return new Date(+p[0], +p[1] - 1, +p[2], +p[3] || 0, +p[4] || 0).getTime();
    }

    // El grafico de un contenedor; si auto-refresh reemplazó el bloque padre
    // entero (cambió la estructura), el contenedor es otro y se crea de nuevo.
    function grafico(tipo) {
        var el = document.querySelector('[data-dash-chart="' + tipo + '"]');
        if (!el) { delete graficos[tipo]; return null; }
        var g = graficos[tipo];
        if (!g || g.getDom() !== el) {
            if (g) { g.dispose(); }
            g = graficos[tipo] = echarts.init(el, null, { renderer: 'canvas' });
        }
        return g;
    }

    function dona(estado) {
        var g = grafico('estado');
        if (!g) { return; }
        var vacio = estado.total === 0;
        g.setOption({
            animationDuration: 500,
            tooltip: { trigger: 'item', formatter: '{b}: <strong>{c}</strong>' },
            title: {
                text: String(estado.total), subtext: estado.total === 1 ? 'reproductor' : 'reproductores',
                left: 'center', top: '34%', itemGap: 2,
                textStyle: { fontSize: 26, fontWeight: 700, color: '#2b3a4a' },
                subtextStyle: { fontSize: 11, color: GRIS }
            },
            series: [{
                type: 'pie', radius: ['64%', '88%'], avoidLabelOverlap: false,
                label: { show: false }, labelLine: { show: false },
                itemStyle: { borderColor: '#fff', borderWidth: 2 },
                emphasis: { scale: true, scaleSize: 4 },
                data: vacio ? [{ name: 'Sin reproductores', value: 1, itemStyle: { color: '#e9ecef' }, tooltip: { show: false } }] : [
                    { name: 'Conectados', value: estado.conectados, itemStyle: { color: VERDE } },
                    { name: 'Desconectados', value: estado.desconectados, itemStyle: { color: ROJO } },
                    { name: 'Sin registrar', value: estado.sin_registrar, itemStyle: { color: GRIS_CLARO } }
                ]
            }]
        });
    }

    function temperatura(series, umbral) {
        var g = grafico('temperatura');
        if (!g) { return; }
        var ahora = Date.now(), angosto = g.getWidth() < 480;
        var datos = series.map(function (s, i) {
            return {
                name: s.nombre, type: 'line', smooth: true, showSymbol: false, symbolSize: 6,
                lineStyle: { width: 2 }, color: PALETA[i % PALETA.length],
                data: s.puntos.map(function (p) { return [ms(p[0]), p[1]]; }),
                // El umbral se marca una sola vez, en la primera serie.
                markLine: i === 0 ? {
                    silent: true, symbol: 'none',
                    lineStyle: { color: ROJO, type: 'dashed', width: 1 },
                    label: { formatter: 'Umbral ' + umbral + ' °C', position: 'insideEndTop', color: ROJO, fontSize: 11 },
                    data: [{ yAxis: umbral }]
                } : undefined
            };
        });
        g.setOption({
            animationDuration: 500,
            grid: { left: 44, right: 16, top: series.length > 1 ? 36 : 16, bottom: 28 },
            legend: { show: series.length > 1, top: 0, icon: 'roundRect', itemWidth: 12, itemHeight: 4, textStyle: { color: GRIS } },
            tooltip: {
                trigger: 'axis',
                formatter: function (ps) {
                    if (!ps.length) { return ''; }
                    var f = new Date(ps[0].value[0]);
                    var h = '<div style="margin-bottom:2px">' + f.toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit' }) +
                            ' ' + f.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' }) + '</div>';
                    ps.forEach(function (p) {
                        h += '<div>' + p.marker + p.seriesName + ': <strong>' +
                             String(Math.round(p.value[1] * 10) / 10).replace('.', ',') + ' °C</strong></div>';
                    });
                    return h;
                }
            },
            xAxis: {
                type: 'time', min: ahora - 24 * 3600e3, max: ahora,
                axisLine: { lineStyle: { color: '#dee2e6' } },
                axisLabel: {
                    color: GRIS, hideOverlap: true,
                    // ECharts pone una marca cada 4 h; en celular las etiquetas
                    // se pisan, así que se deja una de cada dos (16, 00, 08).
                    formatter: function (v) {
                        var h = new Date(v).getHours();
                        if (angosto && h % 8 !== 0) { return ''; }
                        return (h < 10 ? '0' : '') + h + ':00';
                    }
                },
                splitLine: { show: false }
            },
            yAxis: {
                type: 'value', scale: true, max: function (v) { return Math.max(Math.ceil((v.max + 2) / 5) * 5, umbral + 5); },
                axisLabel: { color: GRIS, formatter: '{value} °' },
                splitLine: { lineStyle: { color: '#f1f3f5' } }
            },
            series: datos
        }, { replaceMerge: ['series'] });
    }

    function dibujar() {
        var d = leerDatos();
        if (!d) { return; }
        dona(d.estado);
        temperatura(d.temperatura || [], d.umbral);
    }

    var espera = null;
    window.addEventListener('resize', function () {
        clearTimeout(espera);
        espera = setTimeout(function () {
            Object.keys(graficos).forEach(function (k) { graficos[k].resize(); });
            dibujar();
        }, 150);
    });

    document.addEventListener('spui:refrescado', dibujar);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', dibujar);
    } else {
        dibujar();
    }
})();
