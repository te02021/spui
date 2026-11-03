// Gráficos del Dashboard (29/10/2026, rehecho el 30/10).
//
//   - Dona del estado de los reproductores. Tocar un sector (o su fila de la
//     leyenda) abre un globo encima con los equipos de ese estado, del color
//     del sector. No es un modal: no tapa la página ni frena el refresco.
//   - Un solo gráfico para toda la red (03/11/2026): temperatura, CPU, RAM,
//     disco o latencia, en 24 h, 7 o 30 días, de los equipos que se marquen.
//     Mismo estilo y controles que Telemetría (core/graficos-tiempo.js):
//     arrastrar, rueda, + / − / volver / imagen.
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
    // Un color por equipo en el gráfico de la red: el primero es el azul
    // de Telemetría, para que con un solo equipo se vea igual que allá.
    var PALETA = ['#3387EF', '#7A5AF8', '#1CA1A7', '#C77F0A', '#D6457A', '#4A9930',
                  '#5B6B7F', '#E0662B', '#2E9E8F', '#A35BD6', '#8A9A2C', '#C24B5A'];
    // Con más equipos que esto, el gráfico muestra de entrada sólo los de peor
    // valor y el promedio de la red; el resto se prende desde la leyenda.
    var POCOS = 6, VISIBLES = 5;
    var PROMEDIO = 'Promedio de la red', UMBRAL_SERIE = '__umbral';
    var MIN_VENTANA = 3 * 3600e3;
    var ETIQUETAS = { conectado: 'Conectados', desconectado: 'Desconectados', sin_registrar: 'Sin registrar' };

    var datos = null;
    var dona = null, firmaDona = null;

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

    // ── Gráfico único de la red (03/11/2026, pedido de Mateo) ───────────────
    // Una variable de todos los reproductores, uno por color. Se elige qué
    // mirar (temperatura, CPU, RAM, disco o latencia), el período (24 h, 7 o
    // 30 días) y qué equipos, con casillas. Arranca en la temperatura de las
    // últimas 24 h, que viene en la página; lo demás se pide a
    // /spui/dashboard/serie y se guarda un rato. Lo elegido se recuerda en este
    // navegador.
    var VARIABLES = {
        temp:     { titulo: 'Temperatura del procesador (°C)', corto: 'temperatura', unidad: ' °C', dec: 1,
                    yMax: function (m) { return Math.max(100, Math.ceil(m / 20) * 20); } },
        cpu:      { titulo: 'Uso del procesador (%)', corto: 'uso de CPU', unidad: ' %', dec: 0, yMax: function () { return 100; } },
        ram:      { titulo: 'Memoria RAM en uso (%)', corto: 'RAM en uso', unidad: ' %', dec: 0, yMax: function () { return 100; } },
        disco:    { titulo: 'Disco en uso (%)', corto: 'disco en uso', unidad: ' %', dec: 0, yMax: function () { return 100; } },
        latencia: { titulo: 'Latencia de red con el CMS (ms)', corto: 'latencia', unidad: ' ms', dec: 0,
                    yMax: function (m) {
                        var paso = m <= 50 ? 10 : (m <= 250 ? 50 : 100);
                        return Math.max(paso * 5, Math.ceil(m * 1.15 / paso) * paso);
                    } }
    };
    var PERIODOS = { '24h': ['últimas 24 h', 24, 'Últimas 24 h'], '7d': ['últimos 7 días', 168, 'Últimos 7 días'],
                     '30d': ['últimos 30 días', 720, 'Últimos 30 días'] };
    // Cuánto vale lo pedido al servidor: 24 h cambia cada minuto (como el
    // refresco de la página); 7 y 30 días se arman por hora.
    var VIGENCIA = { '24h': 20e3, '7d': 10 * 60e3, '30d': 10 * 60e3 };
    var GUARDADO = 'spui.dashboard.grafico';

    var elegido = { variable: 'temp', periodo: '24h', ocultos: {} };   // ocultos: id → true (casilla desmarcada)
    try {
        var previo = JSON.parse(localStorage.getItem(GUARDADO) || 'null');
        if (previo && VARIABLES[previo.variable] && PERIODOS[previo.periodo]) {
            elegido = { variable: previo.variable, periodo: previo.periodo, ocultos: previo.ocultos || {} };
        }
    } catch (e) { /* sin almacenamiento: arranca como siempre */ }
    function recordar() {
        try { localStorage.setItem(GUARDADO, JSON.stringify(elegido)); } catch (e) { /* no importa */ }
    }

    var metricas = null, vista = null, firmaDibujo = null, firmaDatos = null;
    var pedidos = {};   // 'variable|periodo' → { datos, cuando }
    var pidiendo = null;
    function clave() { return elegido.variable + '|' + elegido.periodo; }

    /** Los datos de lo elegido: los de la página (temperatura, 24 h) o los últimos pedidos. */
    function serieElegida() {
        if (elegido.variable === 'temp' && elegido.periodo === '24h') { return datos.grafico; }
        var p = pedidos[clave()];
        return p ? p.datos : null;
    }

    /** Pide lo elegido al servidor si no lo tiene, o si lo que tiene ya venció. */
    function pedirSiHaceFalta() {
        if (elegido.variable === 'temp' && elegido.periodo === '24h') { return; }
        var k = clave(), p = pedidos[k];
        if ((p && Date.now() - p.cuando < VIGENCIA[elegido.periodo]) || pidiendo === k) { return; }
        var seccion = document.querySelector('[data-dash-grafico]');
        if (!seccion) { return; }
        pidiendo = k;
        var el = document.querySelector('[data-dash-chart="metricas"]');
        if (el && !p) { el.classList.add('is-cargando'); }
        fetch(seccion.dataset.urlSerie + '?variable=' + encodeURIComponent(elegido.variable) + '&periodo=' + encodeURIComponent(elegido.periodo),
              { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
            .then(function (d) { pedidos[k] = { datos: d, cuando: Date.now() }; })
            .catch(function () { /* queda lo que había; el próximo refresco reintenta */ })
            .then(function () {
                if (pidiendo === k) { pidiendo = null; }
                var el2 = document.querySelector('[data-dash-chart="metricas"]');
                if (el2) { el2.classList.remove('is-cargando'); }
                if (clave() === k) { dibujarMetricas(); }
            });
    }

    function limites() {
        var a = Date.now();
        return { min: a - PERIODOS[elegido.periodo][1] * 3600e3, max: a };
    }

    /** Todas las series por nombre: así cada equipo conserva su color aunque se oculten otros. */
    function todasOrdenadas(g) {
        return (g ? g.series : []).slice().sort(function (a, b) { return a.nombre < b.nombre ? -1 : 1; });
    }
    function colorDe(id) {
        var lista = (datos.reproductores || []).slice().sort(function (a, b) { return a.hostname < b.hostname ? -1 : 1; });
        var i = lista.findIndex(function (r) { return r.id === id; });
        return PALETA[(i < 0 ? 0 : i) % PALETA.length];
    }
    function visibles(g) { return todasOrdenadas(g).filter(function (s) { return !elegido.ocultos[s.id]; }); }
    // Con todos los equipos y muchos: de entrada, los de peor valor y el promedio.
    function resumida(g) { return Object.keys(elegido.ocultos).length === 0 && visibles(g).length > POCOS; }

    /** Promedio de los equipos visibles en cada hora, y al final el de sus últimas lecturas. */
    function promedioRed(series) {
        var porHora = {}, ultimos = [], ultimoT = 0;
        series.forEach(function (s) {
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

    function seriesDe(g) {
        var lista = visibles(g), conArea = !resumida(g) && lista.length <= POCOS;
        var salida = lista.map(function (s) {
            return G.linea(s.nombre, colorDe(s.id), s.puntos.map(function (p) { return [G.ms(p[0]), p[1]]; }), conArea);
        });
        if (resumida(g)) {
            salida.push(G.linea(PROMEDIO, '#2b3a4a', promedioRed(lista), false, { lineStyle: { width: 3, color: '#2b3a4a', type: [6, 4] }, z: 5 }));
        }
        // El umbral va en una serie propia, sin datos y fuera de la leyenda:
        // si colgara de un equipo, ocultarlo escondería también la línea roja.
        if (g && g.umbral !== null && g.umbral !== undefined) {
            salida.push({ type: 'line', name: UMBRAL_SERIE, data: [], silent: true, tooltip: { show: false },
                          markLine: G.lineaLimite(g.umbral, 'Umbral ' + G.num(g.umbral, 0) + VARIABLES[elegido.variable].unidad) });
        }
        return salida;
    }

    /** Leyenda: con muchos equipos, una fila con flechas y de entrada los de peor valor ahora. */
    function leyendaDe(g) {
        var lista = visibles(g), nombres = lista.map(function (s) { return s.nombre; });
        if (!resumida(g)) { return { show: nombres.length > 1, data: nombres }; }
        // Los peores AHORA: primero los que reportaron en el último cuarto de
        // hora (los conectados), ordenados por su última lectura; uno caído
        // hace horas no desplaza a un equipo que está en problemas.
        var reciente = function (s) { return G.ms(s.puntos[s.puntos.length - 1][0]) > Date.now() - 15 * 60e3; };
        var peores = lista.slice().sort(function (a, b) {
            return (reciente(b) - reciente(a)) || (b.puntos[b.puntos.length - 1][1] - a.puntos[a.puntos.length - 1][1]);
        }).slice(0, VISIBLES).map(function (s) { return s.nombre; });
        var sel = {};
        nombres.forEach(function (n) { sel[n] = peores.indexOf(n) !== -1; });
        sel[PROMEDIO] = true;
        return {
            show: true, type: 'scroll', top: 0, left: 0, right: 0, data: [PROMEDIO].concat(nombres), selected: sel,
            pageIconColor: '#446687', pageIconInactiveColor: '#ced4da', pageTextStyle: { color: GRIS }
        };
    }

    function opcionDe(g) {
        var v = VARIABLES[elegido.variable], l = limites(), m = 0;
        visibles(g).forEach(function (s) { s.puntos.forEach(function (p) { m = Math.max(m, p[1] || 0); }); });
        var o = G.opcion({
            min: l.min, max: l.max, desde: l.min, hasta: l.max, minVentana: MIN_VENTANA,
            leyenda: true, yMax: v.yMax(m),
            eje: function (x) { return Math.round(x) + v.unidad; },
            formato: function (x) { return G.num(x, v.dec) + v.unidad; },
            series: seriesDe(g)
        });
        var ley = leyendaDe(g);
        o.legend = Object.assign(o.legend, ley);
        if (!ley.show) { o.grid.top = 18; }
        return o;
    }

    /** Títulos, chips y casillas según lo elegido (se reaplica después de cada refresco). */
    function pintarControles(g) {
        var v = VARIABLES[elegido.variable], p = PERIODOS[elegido.periodo];
        var titulo = document.querySelector('[data-dash-graf-titulo]');
        if (titulo) { titulo.textContent = v.titulo + ' · ' + p[0]; }
        var ayuda = document.querySelector('[data-dash-graf-ayuda]');
        if (ayuda) {
            ayuda.textContent = resumida(g)
                ? 'Se ven los 5 de valor más alto y el promedio de la red: tocá un nombre para mostrarlo u ocultarlo'
                : (elegido.periodo === '24h' ? 'Promedio por hora de cada reproductor' : 'Promedio por hora de cada reproductor; arrastrá para moverte en el tiempo');
        }
        document.querySelectorAll('[data-dash-variable]').forEach(function (b) {
            b.setAttribute('aria-pressed', b.dataset.dashVariable === elegido.variable ? 'true' : 'false');
        });
        document.querySelectorAll('[data-dash-periodo]').forEach(function (b) {
            b.classList.toggle('is-elegida', b.dataset.dashPeriodo === elegido.periodo);
        });
        var vp = document.querySelector('[data-dash-periodo-valor]');
        if (vp) { vp.textContent = p[2]; }
        pintarEquipos(g);
        var el = document.querySelector('[data-dash-chart="metricas"]');
        if (el) { el.setAttribute('aria-label', 'Gráfico de la red: ' + v.corto + ', ' + p[0]); }
    }

    function pintarEquipos(g) {
        var panel = document.getElementById('dash-equipos');
        if (!panel) { return; }
        var equipos = (datos.reproductores || []).slice().sort(function (a, b) { return a.hostname < b.hostname ? -1 : 1; });
        var conDatos = {};
        (g ? g.series : []).forEach(function (s) { conDatos[s.id] = true; });
        var marcados = equipos.filter(function (r) { return !elegido.ocultos[r.id]; }).length;
        var h = '<label class="spui-dash-equipo is-todos"><input type="checkbox" data-dash-equipo-todos' +
                (marcados === equipos.length ? ' checked' : '') + '> Todos</label>';
        equipos.forEach(function (r) {
            h += '<label class="spui-dash-equipo"><input type="checkbox" data-dash-equipo="' + r.id + '"' + (elegido.ocultos[r.id] ? '' : ' checked') + '>' +
                 '<span class="spui-dash-punto" style="background:' + colorDe(r.id) + '"></span>' +
                 '<span>' + escapar(r.hostname) + '</span>' + (conDatos[r.id] ? '' : '<small>sin datos</small>') + '</label>';
        });
        // Sólo si cambió: reescribirlo con el menú abierto le sacaría el foco a la casilla.
        if (panel._spuiHtml !== h) { panel.innerHTML = h; panel._spuiHtml = h; }
        var todos = panel.querySelector('[data-dash-equipo-todos]');
        if (todos) { todos.indeterminate = marcados > 0 && marcados < equipos.length; }
        var valor = document.querySelector('[data-dash-equipos-valor]');
        if (valor) {
            var uno = equipos.filter(function (r) { return !elegido.ocultos[r.id]; });
            valor.textContent = marcados === equipos.length ? 'Todos'
                : (marcados === 0 ? 'Ninguno' : (marcados === 1 ? uno[0].hostname : marcados + ' de ' + equipos.length));
        }
    }

    function dibujarMetricas() {
        var el = document.querySelector('[data-dash-chart="metricas"]');
        var g = serieElegida();
        pintarControles(g);
        pedirSiHaceFalta();
        var nuevo = !metricas || metricas.getDom() !== el;
        metricas = asegurar(metricas, el);
        if (!metricas) { vista = null; return; }

        var vacio = document.querySelector('[data-dash-graf-vacio]');
        var sinNada = !g || !visibles(g).length;
        if (vacio) {
            vacio.hidden = !g || !sinNada;
            vacio.textContent = !g ? '' : (Object.keys(elegido.ocultos).length && g.series.length
                ? 'Ninguno de los reproductores elegidos tiene datos de ' + VARIABLES[elegido.variable].corto + ' en este período.'
                : 'Todavía no hay datos de ' + VARIABLES[elegido.variable].corto + ' en ' + PERIODOS[elegido.periodo][0] + '.');
        }
        if (!g) { return; }   // todavía llegando: queda lo anterior, a medio tono

        var firma = clave() + '|' + JSON.stringify(elegido.ocultos) + '|' + visibles(g).length;
        var firmaD = JSON.stringify(g.series);
        // También si el gráfico ya existía pero sin datos: al recargar con
        // otra variable elegida, se crea antes de que lleguen los pedidos.
        if (nuevo || !vista) {
            metricas.setOption(opcionDe(g));
            vista = new G.Vista(metricas, limites, MIN_VENTANA);
            vista.conRueda(metricas);
            G.herramientas(el.closest('.unraf-card').querySelector('[data-tel-herramientas]'), vista, function () {
                var l2 = limites();
                vista.irA(l2.min, l2.max, 450);
            }, function () { return 'dashboard-' + elegido.variable + '-' + elegido.periodo; });
        } else if (firma !== firmaDibujo) {
            // Otra variable, otro período u otros equipos: el gráfico se rearma
            // de cero (leyenda, colores, eje) y muestra el período entero.
            metricas.setOption(opcionDe(g), { notMerge: true });
            var l = limites();
            vista.actual = null;
            vista.irA(l.min, l.max, 0);
        } else if (firmaD !== firmaDatos) {
            // Llegó una lectura nueva: datos y eje hasta "ahora". Si se estaba
            // mirando el final, la vista acompaña; si el usuario se había ido
            // para atrás o hecho zoom, se queda donde estaba.
            var antes = vista.objetivo || vista.leer();
            var alFinal = antes.e >= vista.limites().max - 5 * 60e3;
            var l3 = limites(), o = opcionDe(g);
            // Sin "legend": así se respeta lo que el usuario prendió o apagó.
            metricas.setOption({ xAxis: { min: l3.min, max: l3.max }, yAxis: o.yAxis, series: o.series });
            vista.aplicar(antes);
            if (alFinal) { vista.irA(l3.max - (antes.e - antes.s), l3.max, 600); }
        }
        firmaDibujo = firma;
        firmaDatos = firmaD;
    }

    // Lo que se elige: variable, período y equipos.
    document.addEventListener('click', function (e) {
        var bv = e.target.closest('[data-dash-variable]');
        if (bv) {
            elegido.variable = bv.dataset.dashVariable;
            recordar();
            dibujarMetricas();
            return;
        }
        var bp = e.target.closest('[data-dash-periodo]');
        if (bp) {
            elegido.periodo = bp.dataset.dashPeriodo;
            recordar();
            dibujarMetricas();
            // Elegido, el menú se cierra (lo maneja desplegable.js).
            var sel = document.querySelector('[aria-controls="dash-periodo"]');
            if (sel && sel.getAttribute('aria-expanded') === 'true') { sel.click(); }
        }
    });
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (t.matches && t.matches('[data-dash-equipo-todos]')) {
            elegido.ocultos = {};
            if (!t.checked) { (datos.reproductores || []).forEach(function (r) { elegido.ocultos[r.id] = true; }); }
        } else if (t.matches && t.matches('[data-dash-equipo]')) {
            var id = parseInt(t.dataset.dashEquipo, 10);
            if (t.checked) { delete elegido.ocultos[id]; } else { elegido.ocultos[id] = true; }
        } else {
            return;
        }
        recordar();
        dibujarMetricas();
    });

    // ── Ciclo ───────────────────────────────────────────────────────────────
    function dibujar() {
        datos = leerDatos();
        if (!datos) { return; }
        aplicarAvisos();
        dibujarDona();
        dibujarMetricas();
        // Si el globo está abierto, que muestre lo que acaba de llegar.
        if (globo && !globo.hidden && globoEstado) { contenidoGlobo(); ubicarGlobo(); }
    }

    var espera = null;
    window.addEventListener('resize', function () {
        cerrarGlobo();
        clearTimeout(espera);
        espera = setTimeout(function () {
            if (dona) { dona.resize(); }
            if (metricas) { metricas.resize(); }
        }, 120);
    });

    document.addEventListener('spui:refrescado', dibujar);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', dibujar);
    } else {
        dibujar();
    }
})();
