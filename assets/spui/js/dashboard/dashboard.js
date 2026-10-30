// Gráficos del Dashboard (29/10/2026, rehecho el 30/10).
//
//   - Dona del estado de los reproductores. Tocar un sector (o su fila de la
//     leyenda) abre un globo encima con los equipos de ese estado, del color
//     del sector. No es un modal: no tapa la página ni frena el refresco.
//   - Temperatura de toda la red en 24 h, con el mismo estilo y los mismos
//     controles que Telemetría (core/graficos-tiempo.js): arrastrar, rueda,
//     + / − / volver / imagen.
//
// Los contenedores llevan data-spui-refresh-conservar, así que auto-refresh.js
// no los toca: reemplaza el <script data-dash-datos> con los números nuevos y
// avisa con spui:refrescado, y acá se redibuja sin destruir el canvas.
(function () {
    'use strict';

    var G = window.spui && window.spui.graficosTiempo;
    if (typeof window.echarts === 'undefined' || !G) { return; }
    var echarts = window.echarts;

    var GRIS = '#6c757d';
    // Un color por equipo en el gráfico de temperatura: el primero es el azul
    // de Telemetría, para que con un solo equipo se vea igual que allá.
    var PALETA = ['#3387EF', '#7A5AF8', '#1CA1A7', '#C77F0A', '#D6457A', '#4A9930', '#5B6B7F'];
    var VENTANA = 24 * 3600e3, MIN_VENTANA = 3 * 3600e3;
    var ETIQUETAS = { conectado: 'Conectados', desconectado: 'Desconectados', sin_registrar: 'Sin registrar' };

    var datos = null;
    var dona = null, temp = null, vistaTemp = null, firmaTemp = null;

    function leerDatos() {
        var nodo = document.querySelector('[data-dash-datos]');
        if (!nodo) { return null; }
        try { return JSON.parse(nodo.textContent); } catch (e) { return null; }
    }

    /** El gráfico de un contenedor; si auto-refresh reemplazó el bloque, se crea de nuevo. */
    function asegurar(actual, el) {
        if (actual && actual.getDom() === el) { return actual; }
        if (actual) { actual.dispose(); }
        return el ? echarts.init(el, null, { locale: 'ES', renderer: 'canvas' }) : null;
    }

    // ── Dona ────────────────────────────────────────────────────────────────
    function dibujarDona() {
        var el = document.querySelector('[data-dash-chart="estado"]');
        var nueva = !dona || dona.getDom() !== el;
        dona = asegurar(dona, el);
        if (!dona) { return; }
        if (nueva) {
            dona.on('click', function (p) {
                if (p.data && p.data.estado) { abrirGlobo(p.data.estado, p.event.event.clientX, p.event.event.clientY); }
            });
        }
        var e = datos.estado, vacio = e.total === 0;
        dona.setOption({
            animationDuration: 600,
            tooltip: {
                trigger: 'item',
                backgroundColor: 'rgba(255,255,255,.97)', borderColor: '#dee2e6', borderWidth: 1,
                textStyle: { color: '#2b3a4a', fontSize: 12 },
                formatter: function (p) {
                    return p.marker + p.name + ': <strong>' + p.value + '</strong>' +
                        '<div style="color:' + GRIS + ';font-size:11px">Tocá para ver cuáles</div>';
                }
            },
            title: {
                text: String(e.total), subtext: e.total === 1 ? 'reproductor' : 'reproductores',
                left: 'center', top: '36%', itemGap: 2,
                textStyle: { fontSize: 26, fontWeight: 700, color: '#2b3a4a' },
                subtextStyle: { fontSize: 11, color: GRIS }
            },
            series: [{
                type: 'pie', radius: ['66%', '90%'], avoidLabelOverlap: false,
                label: { show: false }, labelLine: { show: false },
                itemStyle: { borderColor: '#fff', borderWidth: 2 },
                emphasis: { scale: true, scaleSize: 5 },
                cursor: 'pointer',
                data: vacio ? [{ name: 'Sin reproductores', value: 1, itemStyle: { color: '#e9ecef' }, tooltip: { show: false }, silent: true }] :
                    ['conectado', 'desconectado', 'sin_registrar'].filter(function (k) { return e[k] > 0; }).map(function (k) {
                        return { name: ETIQUETAS[k], value: e[k], estado: k, itemStyle: { color: datos.colores[k] } };
                    })
            }]
        }, { replaceMerge: ['series'] });
    }

    // ── Globo de la dona ────────────────────────────────────────────────────
    var globo = null, globoEstado = null, globoAncla = null, globoOrigen = null;

    function escapar(t) {
        return String(t === null || t === undefined ? '' : t).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function haceCuanto(iso) {
        if (!iso) { return 'Nunca se conectó'; }
        var s = (Date.now() - new Date(iso).getTime()) / 1000;
        if (s < 90) { return 'Última señal hace instantes'; }
        if (s < 3600) { return 'Última señal hace ' + Math.round(s / 60) + ' min'; }
        if (s < 86400) { return 'Última señal hace ' + Math.round(s / 3600) + ' h'; }
        return 'Última señal hace ' + Math.round(s / 86400) + ' días';
    }

    function crearGlobo() {
        globo = document.createElement('div');
        globo.className = 'spui-dash-globo';
        globo.setAttribute('role', 'dialog');
        globo.hidden = true;
        document.body.appendChild(globo);
        globo.addEventListener('click', function (e) {
            if (e.target.closest('[data-dash-globo-cerrar]')) { cerrarGlobo(true); }
        });
    }

    function contenidoGlobo() {
        var lista = datos.reproductores.filter(function (r) { return r.estado === globoEstado; });
        var color = datos.colores[globoEstado];
        globo.style.setProperty('--globo', color);
        globo.setAttribute('aria-label', ETIQUETAS[globoEstado]);
        var h = '<div class="spui-dash-globo-cab"><span class="spui-dash-punto" style="background:' + color + '"></span>' +
                '<strong>' + ETIQUETAS[globoEstado] + '</strong><span class="spui-dash-globo-cuenta">' + lista.length + '</span>' +
                '<button type="button" class="spui-dash-globo-cerrar" data-dash-globo-cerrar aria-label="Cerrar">×</button></div>';
        if (!lista.length) {
            h += '<p class="spui-dash-globo-vacio">Ya no hay reproductores en este estado.</p>';
        } else {
            h += '<ul class="spui-dash-globo-lista">';
            lista.forEach(function (r) {
                h += '<li><a href="' + escapar(r.url) + '">' +
                     '<span class="spui-dash-globo-nombre">' + escapar(r.hostname) + '</span>' +
                     '<span class="spui-dash-globo-detalle">' + escapar(r.pantallas || 'Sin pantallas') + '</span>' +
                     '<span class="spui-dash-globo-detalle">' + escapar(haceCuanto(r.desde)) + ' · ver telemetría →</span>' +
                     '</a></li>';
            });
            h += '</ul>';
        }
        globo.innerHTML = h + '<span class="spui-dash-globo-flecha" aria-hidden="true"></span>';
    }

    /** Ubica el globo arriba del punto tocado (abajo si no entra), sin salirse de la pantalla. */
    function ubicarGlobo() {
        var margen = 16, sep = 14;
        var x = globoAncla.x, y = globoAncla.y;
        var ancho = globo.offsetWidth, alto = globo.offsetHeight, vw = document.documentElement.clientWidth;
        var izq = Math.max(margen, Math.min(x - ancho / 2, vw - margen - ancho));
        var arriba = y - sep - alto >= margen;
        var top = arriba ? y - sep - alto : y + sep;
        globo.classList.toggle('is-abajo', !arriba);
        globo.style.left = (izq + window.scrollX) + 'px';
        globo.style.top = (top + window.scrollY) + 'px';
        globo.style.setProperty('--flecha', Math.max(18, Math.min(ancho - 18, x - izq)) + 'px');
    }

    function abrirGlobo(estado, x, y, origen) {
        if (!globo) { crearGlobo(); }
        globoEstado = estado;
        globoAncla = { x: x, y: y };
        globoOrigen = origen || null;
        contenidoGlobo();
        globo.hidden = false;
        ubicarGlobo();
        globo.classList.remove('is-visible');
        void globo.offsetWidth;
        globo.classList.add('is-visible');
        var cerrar = globo.querySelector('[data-dash-globo-cerrar]');
        if (origen && cerrar) { cerrar.focus(); }
    }

    function cerrarGlobo(devolverFoco) {
        if (!globo || globo.hidden) { return; }
        globo.hidden = true;
        globo.classList.remove('is-visible');
        if (devolverFoco && globoOrigen && document.contains(globoOrigen)) { globoOrigen.focus(); }
        globoEstado = null;
    }

    // Leyenda: la misma acción con teclado o tocando la fila.
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-dash-estado]');
        if (b) {
            var r = b.getBoundingClientRect();
            if (globoEstado === b.dataset.dashEstado && !globo.hidden) { cerrarGlobo(); return; }
            abrirGlobo(b.dataset.dashEstado, r.left + r.width / 2, r.top, b);
            return;
        }
        // Afuera del globo y de la dona: se cierra.
        if (globo && !globo.hidden && !globo.contains(e.target) && !e.target.closest('[data-dash-chart="estado"]')) {
            cerrarGlobo();
        }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { cerrarGlobo(true); } });

    // ── Temperatura ─────────────────────────────────────────────────────────
    function limitesTemp() { var a = Date.now(); return { min: a - VENTANA, max: a }; }

    function seriesTemp() {
        return (datos.temperatura || []).map(function (s, i) {
            return G.linea(s.nombre, PALETA[i % PALETA.length], s.puntos.map(function (p) { return [G.ms(p[0]), p[1]]; }), true,
                i === 0 ? { markLine: G.lineaLimite(datos.umbral, 'Umbral ' + datos.umbral + ' °C') } : {});
        });
    }
    function maxTemp() {
        var m = 0;
        (datos.temperatura || []).forEach(function (s) { s.puntos.forEach(function (p) { m = Math.max(m, p[1] || 0); }); });
        return Math.max(100, Math.ceil(m / 20) * 20);
    }

    function dibujarTemp() {
        var el = document.querySelector('[data-dash-chart="temperatura"]');
        var firma = JSON.stringify(datos.temperatura || []);
        var nuevo = !temp || temp.getDom() !== el;
        temp = asegurar(temp, el);
        if (!temp) { vistaTemp = null; return; }

        if (nuevo) {
            var l = limitesTemp();
            temp.setOption(G.opcion({
                min: l.min, max: l.max, desde: l.min, hasta: l.max, minVentana: MIN_VENTANA,
                leyenda: (datos.temperatura || []).length > 1,
                yMax: maxTemp(), eje: function (x) { return Math.round(x) + ' °C'; },
                formato: function (x) { return G.num(x, 1) + ' °C'; },
                series: seriesTemp()
            }));
            vistaTemp = new G.Vista(temp, limitesTemp, MIN_VENTANA);
            vistaTemp.conRueda(temp);
            G.herramientas(el.closest('.unraf-card').querySelector('[data-tel-herramientas]'), vistaTemp, function () {
                var l2 = limitesTemp();
                vistaTemp.irA(l2.min, l2.max, 450);
            }, function () { return 'dashboard-temperatura'; });
        } else if (firma !== firmaTemp) {
            // Llegó una hora nueva: sólo los datos, sin tocar el zoom que haya puesto el usuario.
            var l3 = limitesTemp();
            temp.setOption({ xAxis: { min: l3.min, max: l3.max }, yAxis: { max: maxTemp(), interval: maxTemp() / 5 }, series: seriesTemp() });
        }
        firmaTemp = firma;
    }

    // ── Ciclo ───────────────────────────────────────────────────────────────
    function dibujar() {
        datos = leerDatos();
        if (!datos) { return; }
        dibujarDona();
        dibujarTemp();
        // Si el globo está abierto, que muestre lo que acaba de llegar.
        if (globo && !globo.hidden && globoEstado) { contenidoGlobo(); ubicarGlobo(); }
    }

    var espera = null;
    window.addEventListener('resize', function () {
        cerrarGlobo();
        clearTimeout(espera);
        espera = setTimeout(function () {
            if (dona) { dona.resize(); }
            if (temp) { temp.resize(); }
        }, 120);
    });

    document.addEventListener('spui:refrescado', dibujar);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', dibujar);
    } else {
        dibujar();
    }
})();
