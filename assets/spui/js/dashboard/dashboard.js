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
    var PALETA = ['#3387EF', '#7A5AF8', '#1CA1A7', '#C77F0A', '#D6457A', '#4A9930',
                  '#5B6B7F', '#E0662B', '#2E9E8F', '#A35BD6', '#8A9A2C', '#C24B5A'];
    // Con más equipos que esto, el gráfico muestra de entrada sólo los más
    // calientes y el promedio de la red; el resto se prende desde la leyenda.
    var POCOS = 6, VISIBLES = 5;
    var PROMEDIO = 'Promedio de la red', UMBRAL_SERIE = '__umbral';
    var VENTANA = 24 * 3600e3, MIN_VENTANA = 3 * 3600e3;
    var ETIQUETAS = { conectado: 'Conectados', desconectado: 'Desconectados', sin_registrar: 'Sin registrar' };

    var datos = null;
    var dona = null, firmaDona = null, temp = null, vistaTemp = null, firmaTemp = null, cantidadTemp = 0;

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
        // Sólo se redibuja si cambió algún número: cada refresco (20 s o un
        // aviso MQTT) trae los mismos datos casi siempre, y redibujar con la
        // animación de entrada hacía que la dona "se recargara" a cada rato.
        var e = datos.estado, vacio = e.total === 0;
        var firma = [e.conectado, e.desconectado, e.sin_registrar].join('/');
        if (!nueva && firma === firmaDona) { return; }
        firmaDona = firma;
        el.setAttribute('aria-label', e.conectado + ' conectados, ' + e.desconectado + ' desconectados, ' + e.sin_registrar + ' sin registrar');
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
    // ── Desplegable de avisos ───────────────────────────────────────────────
    // Abierto o cerrado según aria-expanded del botón, que auto-refresh.js no
    // toca; si un refresco reemplaza la cabecera entera, aplicarAvisos() lo
    // vuelve a dejar como estaba.
    var avisosAbierto = false;
    function aplicarAvisos() {
        var b = document.querySelector('[data-dash-avisos-btn]');
        if (b) { b.setAttribute('aria-expanded', avisosAbierto ? 'true' : 'false'); }
    }
    function abrirAvisos(abrir, conFoco) {
        avisosAbierto = abrir;
        aplicarAvisos();
        if (abrir) { cerrarGlobo(); }
        if (!conFoco) { return; }
        var destino = abrir ? document.querySelector('[data-dash-avisos-cerrar]') : document.querySelector('[data-dash-avisos-btn]');
        if (destino) { destino.focus(); }
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-dash-avisos-btn]')) { abrirAvisos(!avisosAbierto, e.detail === 0); return; }
        if (e.target.closest('[data-dash-avisos-cerrar]')) { abrirAvisos(false, true); return; }
        if (avisosAbierto && !e.target.closest('.spui-dash-avisos')) { abrirAvisos(false); }

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
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        if (avisosAbierto) { abrirAvisos(false, true); }
        cerrarGlobo(true);
    });

    // ── Temperatura ─────────────────────────────────────────────────────────
    function limitesTemp() { var a = Date.now(); return { min: a - VENTANA, max: a }; }

    /** Las series por nombre: así cada equipo conserva su color entre refrescos. */
    function seriesOrdenadas() {
        return (datos.temperatura || []).slice().sort(function (a, b) { return a.nombre < b.nombre ? -1 : 1; });
    }
    function muchos() { return (datos.temperatura || []).length > POCOS; }

    /** Promedio de todos los equipos en cada hora, y al final el de sus últimas lecturas. */
    function promedioRed() {
        var porHora = {}, ultimos = [], ultimoT = 0;
        (datos.temperatura || []).forEach(function (s) {
            s.puntos.forEach(function (p) {
                if (p[0].length === 16) { (porHora[p[0]] = porHora[p[0]] || []).push(p[1]); }   // "Y-m-d H:00"
                else { ultimos.push(p[1]); ultimoT = Math.max(ultimoT, G.ms(p[0])); }           // lectura más reciente
            });
        });
        var prom = function (l) { return Math.round(l.reduce(function (a, b) { return a + b; }, 0) / l.length * 10) / 10; };
        var puntos = Object.keys(porHora).sort().map(function (k) { return [G.ms(k), prom(porHora[k])]; });
        if (ultimos.length) { puntos.push([ultimoT, prom(ultimos)]); }
        return puntos;
    }

    function seriesTemp() {
        var conArea = !muchos();
        var lista = seriesOrdenadas().map(function (s, i) {
            return G.linea(s.nombre, PALETA[i % PALETA.length], s.puntos.map(function (p) { return [G.ms(p[0]), p[1]]; }), conArea);
        });
        if (muchos()) {
            lista.push(G.linea(PROMEDIO, '#2b3a4a', promedioRed(), false, { lineStyle: { width: 3, color: '#2b3a4a', type: [6, 4] }, z: 5 }));
        }
        // El umbral va en una serie propia, sin datos y fuera de la leyenda:
        // si colgara de un equipo, ocultarlo escondería también la línea roja.
        lista.push({ type: 'line', name: UMBRAL_SERIE, data: [], silent: true, tooltip: { show: false },
                     markLine: G.lineaLimite(datos.umbral, 'Umbral ' + datos.umbral + ' °C') });
        return lista;
    }

    /** Leyenda: con muchos equipos, una sola fila con flechas y de entrada los más calientes. */
    function leyendaTemp() {
        var series = seriesOrdenadas();
        var nombres = series.map(function (s) { return s.nombre; });
        if (!muchos()) {
            return { show: nombres.length > 1, data: nombres };
        }
        // Los más calientes AHORA: primero los que reportaron en el último
        // cuarto de hora (los conectados), ordenados por su última lectura;
        // uno caído hace horas no desplaza a un equipo que está calentando.
        var reciente = function (s) { return G.ms(s.puntos[s.puntos.length - 1][0]) > Date.now() - 15 * 60e3; };
        var calientes = series.slice().sort(function (a, b) {
            return (reciente(b) - reciente(a)) || (b.puntos[b.puntos.length - 1][1] - a.puntos[a.puntos.length - 1][1]);
        }).slice(0, VISIBLES).map(function (s) { return s.nombre; });
        var elegidos = {};
        nombres.forEach(function (n) { elegidos[n] = calientes.indexOf(n) !== -1; });
        elegidos[PROMEDIO] = true;
        return {
            show: true, type: 'scroll', top: 0, left: 0, right: 0, data: [PROMEDIO].concat(nombres), selected: elegidos,
            pageIconColor: '#446687', pageIconInactiveColor: '#ced4da', pageTextStyle: { color: GRIS }
        };
    }
    function maxTemp() {
        var m = 0;
        (datos.temperatura || []).forEach(function (s) { s.puntos.forEach(function (p) { m = Math.max(m, p[1] || 0); }); });
        return Math.max(100, Math.ceil(m / 20) * 20);
    }

    function opcionTemp() {
        var l = limitesTemp();
        var o = G.opcion({
            min: l.min, max: l.max, desde: l.min, hasta: l.max, minVentana: MIN_VENTANA,
            leyenda: true,
            yMax: maxTemp(), eje: function (x) { return Math.round(x) + ' °C'; },
            formato: function (x) { return G.num(x, 1) + ' °C'; },
            series: seriesTemp()
        });
        var ley = leyendaTemp();
        o.legend = Object.assign(o.legend, ley);
        if (!ley.show) { o.grid.top = 18; }
        cantidadTemp = (datos.temperatura || []).length;
        return o;
    }

    function dibujarTemp() {
        var el = document.querySelector('[data-dash-chart="temperatura"]');
        var firma = JSON.stringify(datos.temperatura || []);
        var nuevo = !temp || temp.getDom() !== el;
        temp = asegurar(temp, el);
        if (!temp) { vistaTemp = null; return; }

        if (nuevo) {
            temp.setOption(opcionTemp());
            vistaTemp = new G.Vista(temp, limitesTemp, MIN_VENTANA);
            vistaTemp.conRueda(temp);
            G.herramientas(el.closest('.unraf-card').querySelector('[data-tel-herramientas]'), vistaTemp, function () {
                var l2 = limitesTemp();
                vistaTemp.irA(l2.min, l2.max, 450);
            }, function () { return 'dashboard-temperatura'; });
        } else if (firma !== firmaTemp && (datos.temperatura || []).length !== cantidadTemp) {
            // Cambió la cantidad de equipos con datos: leyenda y colores se
            // rearman de cero sobre el mismo gráfico (notMerge), así no quedan
            // series viejas; la vista vuelve a donde estaba.
            var previa = vistaTemp.objetivo || vistaTemp.leer();
            temp.setOption(opcionTemp(), { notMerge: true });
            vistaTemp.aplicar(previa);
        } else if (firma !== firmaTemp) {
            // Llegó una lectura nueva (cada minuto, el último punto de cada
            // equipo): datos y eje hasta "ahora". Si se estaba mirando el final,
            // la vista acompaña; si el usuario se había ido para atrás o hecho
            // zoom, se queda donde estaba.
            var antes = vistaTemp.objetivo || vistaTemp.leer();
            var alFinal = antes.e >= vistaTemp.limites().max - 5 * 60e3;
            var l3 = limitesTemp();
            // Sin "legend": así se respeta lo que el usuario prendió o apagó.
            temp.setOption({ xAxis: { min: l3.min, max: l3.max }, yAxis: { max: maxTemp(), interval: maxTemp() / 5 }, series: seriesTemp() });
            vistaTemp.aplicar(antes);
            if (alFinal) { vistaTemp.irA(l3.max - (antes.e - antes.s), l3.max, 600); }
        }
        firmaTemp = firma;
    }

    // ── Ciclo ───────────────────────────────────────────────────────────────
    function dibujar() {
        datos = leerDatos();
        if (!datos) { return; }
        aplicarAvisos();
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
