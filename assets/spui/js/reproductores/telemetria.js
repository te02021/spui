// Telemetría de un reproductor (templates/reproductores/telemetria.html.twig).
//
// Tres partes que comparten los indicadores de arriba:
//   - Histórico por hora: tres gráficos ApexCharts con escala fija. Cambiar de
//     rango pide /telemetria/datos y redibuja con animación, sin recargar.
//     Cada minuto se vuelve a pedir el rango actual, en silencio.
//   - En vivo: suscrito por WebSocket a spui/telemetria/vivo/{id}. Mientras la
//     pestaña está visible, se le pide a la Pi (POST /telemetria/vivo) que
//     publique cada 1 s, y se renueva a la mitad del plazo. Al cerrar o ocultar
//     la pestaña se deja de renovar y la Pi vuelve sola a publicar cada minuto.
//   - Exportar: rango de fechas → Excel. Los errores van debajo de cada campo.
(function () {
    'use strict';

    var raiz = document.getElementById('spui-telemetria');
    if (!raiz || typeof window.ApexCharts === 'undefined') return;

    var AZUL = '#3387EF', AMBAR = '#C77F0A', ROJO = '#FF223D', VERDE = '#4A9930', GRIS = '#6c757d';
    var UMBRAL = parseFloat(raiz.dataset.umbral) || 80;
    var VIVO_SEG = parseInt(raiz.dataset.vivoSeg, 10) || 120;
    var VIVO_PUNTOS = 120;           // 2 minutos a una lectura por segundo
    var SIN_DATOS_VIVO_MS = 15000;   // pedido hecho y nada llegó: la Pi no responde
    var REFRESCO_MS = 60000;
    var CARGA_MIN_MS = 450;          // la animación de carga se ve aunque el pedido vuele

    var datos = JSON.parse(raiz.querySelector('[data-tel-datos]').textContent);
    var rango = datos.rango;
    var vivoActivo = false;          // llegó al menos una muestra en vivo y no se pausó

    // ── Idioma de los gráficos ──────────────────────────────────────────────
    var ES = {
        name: 'es',
        options: {
            months: ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'],
            shortMonths: ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'],
            days: ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'],
            shortDays: ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'],
            toolbar: { exportToSVG: 'Descargar SVG', exportToPNG: 'Descargar PNG', exportToCSV: 'Descargar CSV',
                       selection: 'Selección', selectionZoom: 'Zoom', zoomIn: 'Acercar', zoomOut: 'Alejar', pan: 'Mover', reset: 'Restablecer' }
        }
    };

    function num(v, dec) { return (v === null || v === undefined || isNaN(v)) ? '—' : Number(v).toFixed(dec).replace('.', ','); }
    function colorTemp(t) { return t >= UMBRAL ? ROJO : (t >= UMBRAL * 0.85 ? AMBAR : VERDE); }
    function colorRam(r) { return r >= 90 ? ROJO : (r >= 75 ? AMBAR : AZUL); }
    /** "2026-10-29 08:00" → milisegundos, en hora local (como lo guarda el CMS). */
    function ms(periodo) {
        var p = periodo.split(/[- :]/);
        return new Date(+p[0], +p[1] - 1, +p[2], +p[3], +(p[4] || 0)).getTime();
    }

    function base(alto, extra) {
        var o = {
            chart: {
                height: alto, fontFamily: 'inherit', locales: [ES], defaultLocale: 'es',
                toolbar: { show: true, tools: { download: true, selection: false, zoom: true, zoomin: true, zoomout: true, pan: false, reset: true } },
                zoom: { enabled: true, type: 'x' },
                animations: { enabled: true, easing: 'easeinout', speed: 800, dynamicAnimation: { enabled: true, speed: 600 } }
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

    // ── Gráficos del histórico ──────────────────────────────────────────────
    function series(serie) {
        return {
            temp: [
                { name: 'Promedio', data: serie.map(function (p) { return [ms(p.periodo), p.temp_prom]; }) },
                { name: 'Máxima',   data: serie.map(function (p) { return [ms(p.periodo), p.temp_max]; }) }
            ],
            ram:   [{ name: 'RAM promedio', data: serie.map(function (p) { return [ms(p.periodo), p.ram_prom]; }) }],
            disco: [{ name: 'Disco libre',  data: serie.map(function (p) { return [ms(p.periodo), Math.round(p.disco_min / 102.4) / 10]; }) }]
        };
    }
    function maxTemp(serie) {
        var m = serie.reduce(function (a, p) { return Math.max(a, p.temp_max || 0); }, 0);
        return Math.max(100, Math.ceil(m / 10) * 10);
    }
    /** Tope del eje del disco: 4 marcas de un paso "redondo" (25, 40, 50 GB…), con aire arriba de la línea. */
    function maxDisco(s) {
        var m = s.disco[0].data.reduce(function (a, p) { return Math.max(a, p[1] || 0); }, 0);
        var pasos = [0.5, 1, 2, 2.5, 5, 10, 20, 25, 40, 50, 75, 100, 150, 200, 250, 500];
        var paso = pasos.filter(function (p) { return p * 4 >= m * 1.15; })[0] || 500;
        return paso * 4;
    }

    var s0 = series(datos.serie);
    var graf = {};
    graf.temp = new ApexCharts(raiz.querySelector('[data-tel-chart="temp"]'), base(320, {
        series: s0.temp,
        chart: Object.assign(base(320).chart, { type: 'area' }),
        colors: [AZUL, AMBAR],
        fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.02 } },
        yaxis: { min: 0, max: maxTemp(datos.serie), tickAmount: 5, title: { text: '°C', style: { color: GRIS } },
                 labels: { formatter: function (v) { return Math.round(v) + ' °C'; } } },
        tooltip: { shared: true, x: { format: 'dd/MM/yyyy HH:mm' }, y: { formatter: function (v) { return num(v, 1) + ' °C'; } } },
        annotations: { yaxis: [{ y: UMBRAL, borderColor: ROJO, strokeDashArray: 6,
            label: { text: 'Umbral ' + UMBRAL + ' °C', borderColor: ROJO, style: { color: '#fff', background: ROJO } } }] }
    }));
    graf.ram = new ApexCharts(raiz.querySelector('[data-tel-chart="ram"]'), base(260, {
        series: s0.ram,
        chart: Object.assign(base(260).chart, { type: 'area' }),
        colors: [AZUL],
        fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.02 } },
        yaxis: { min: 0, max: 100, tickAmount: 5, labels: { formatter: function (v) { return Math.round(v) + ' %'; } } },
        tooltip: { x: { format: 'dd/MM/yyyy HH:mm' }, y: { formatter: function (v) { return num(v, 1) + ' %'; } } }
    }));
    graf.disco = new ApexCharts(raiz.querySelector('[data-tel-chart="disco"]'), base(260, {
        series: s0.disco,
        chart: Object.assign(base(260).chart, { type: 'line' }),
        colors: [VERDE],
        yaxis: { min: 0, max: maxDisco(s0), tickAmount: 4, labels: { formatter: function (v) { return num(v, (v === 0 || v >= 10) ? 0 : 1) + ' GB'; } } },
        tooltip: { x: { format: 'dd/MM/yyyy HH:mm' }, y: { formatter: function (v) { return num(v, 1) + ' GB'; } } }
    }));
    Object.keys(graf).forEach(function (k) { graf[k].render(); });

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
    var gTemp = gauge(raiz.querySelector('[data-tel-gauge="temp"]'), VERDE);
    var gRam  = gauge(raiz.querySelector('[data-tel-gauge="ram"]'), AZUL);

    function ponerGauge(g, el, valor, texto, color) {
        el._texto = texto;
        g.updateOptions({ colors: [color] }, false, false);
        g.updateSeries([Math.max(0, Math.min(100, valor || 0))]);
    }
    function indicadores(temp, ram) {
        if (temp !== null && temp !== undefined) ponerGauge(gTemp, gTemp.el, temp, num(temp, 1) + ' °C', colorTemp(temp));
        if (ram !== null && ram !== undefined) ponerGauge(gRam, gRam.el, ram, num(ram, 1) + ' %', colorRam(ram));
    }

    /** El número sube de 0 al valor la primera vez, como los indicadores circulares. */
    function contar(el, valor, dec) {
        if (valor === null || valor === undefined) { el.textContent = '—'; return; }
        var desde = parseFloat(String(el.dataset.valor || '0')) || 0, t0 = null, dur = 1100;
        el.dataset.valor = valor;
        function paso(t) {
            if (t0 === null) t0 = t;
            var k = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - k, 3);
            el.textContent = num(desde + (valor - desde) * e, dec);
            if (k < 1) requestAnimationFrame(paso);
        }
        requestAnimationFrame(paso);
    }

    function resumen(r) {
        var txt = {
            temp_prom: r ? num(r.temp_prom, 1) + ' °C' : '—',
            temp_max:  r ? num(r.temp_max, 1) + ' °C' : '—',
            ram_prom:  r ? num(r.ram_prom, 1) + ' %' : '—',
            ram_max:   r ? num(r.ram_max, 1) + ' %' : '—',
            disco_min: r ? num(r.disco_min / 1024, 1) + ' GB' : '—'
        };
        Object.keys(txt).forEach(function (k) {
            var el = raiz.querySelector('[data-tel-res="' + k + '"]');
            if (el) {
                el.textContent = txt[k];
                el.classList.toggle('text-danger', k === 'temp_max' && r && r.temp_max >= UMBRAL);
            }
        });
    }

    function pintar(d) {
        var vacio = !d.serie.length;
        raiz.querySelector('[data-tel-vacio]').hidden = !vacio;
        raiz.querySelector('[data-tel-graficos]').hidden = vacio;
        resumen(d.resumen);
        if (!vivoActivo && d.ultima) {
            indicadores(d.ultima.temp, d.ultima.ram);
        }
        if (d.ultima) {
            contar(raiz.querySelector('[data-tel-numero="disco"]'), d.ultima.disco_gb, 1);
            if (!vivoActivo) raiz.querySelector('[data-tel-ultima]').textContent = 'Última lectura guardada: ' + d.ultima.cuando + '.';
        }
        if (vacio) return;
        var s = series(d.serie);
        graf.temp.updateOptions({ yaxis: Object.assign({}, graf.temp.opts.yaxis[0], { max: maxTemp(d.serie) }) }, false, false);
        graf.disco.updateOptions({ yaxis: Object.assign({}, graf.disco.opts.yaxis[0], { max: maxDisco(s) }) }, false, false);
        graf.temp.updateSeries(s.temp, true);
        graf.ram.updateSeries(s.ram, true);
        graf.disco.updateSeries(s.disco, true);
    }
    pintar(datos);

    // ── Cambio de rango y refresco ──────────────────────────────────────────
    var cargando = raiz.querySelector('[data-tel-cargando]');
    var historico = raiz.querySelector('[data-tel-historico]');

    function pedir(nuevo, silencioso) {
        var t0 = Date.now();
        if (!silencioso) { cargando.hidden = false; historico.classList.add('is-cargando'); }
        var url = raiz.dataset.urlDatos + '?rango=' + encodeURIComponent(nuevo);
        return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (d) {
                return new Promise(function (ok) { setTimeout(ok, silencioso ? 0 : Math.max(0, CARGA_MIN_MS - (Date.now() - t0))); })
                    .then(function () { rango = nuevo; datos = d; pintar(d); });
            })
            .catch(function () { /* sin red: se queda lo que había; el próximo refresco reintenta */ })
            .then(function () { cargando.hidden = true; historico.classList.remove('is-cargando'); });
    }

    raiz.querySelectorAll('[data-tel-rango]').forEach(function (b) {
        b.addEventListener('click', function () {
            var nuevo = b.dataset.telRango;
            if (nuevo === rango && !historico.classList.contains('is-cargando')) return;
            raiz.querySelectorAll('[data-tel-rango]').forEach(function (o) {
                var sel = o === b;
                o.classList.toggle('unraf-btn-institucion', sel);
                o.classList.toggle('unraf-btn-light', !sel);
                o.setAttribute('aria-pressed', sel ? 'true' : 'false');
            });
            try { history.replaceState(null, '', '?rango=' + nuevo); } catch (e) { /* sin historial */ }
            pedir(nuevo, false);
        });
    });
    setInterval(function () { if (!document.hidden) pedir(rango, true); }, REFRESCO_MS);

    // ── En vivo ─────────────────────────────────────────────────────────────
    var btnVivo = raiz.querySelector('[data-tel-vivo-btn]');
    var panelVivo = raiz.querySelector('[data-tel-vivo-panel]');
    if (btnVivo && raiz.dataset.mqttUrl && typeof window.mqtt !== 'undefined') {
        var textoVivo = btnVivo.querySelector('[data-tel-vivo-texto]');
        var pausado = false, cliente = null, renovar = null, sinDatos = null, ultimaMuestra = 0;
        var puntos = { temp: [], ram: [] };

        function chartVivo(sel, nombre, color, max, unidad) {
            var c = new ApexCharts(raiz.querySelector(sel), {
                series: [{ name: nombre, data: [] }],
                chart: { type: 'line', height: 220, fontFamily: 'inherit', toolbar: { show: false }, zoom: { enabled: false },
                         animations: { enabled: true, easing: 'linear', dynamicAnimation: { speed: 900 } } },
                colors: [color], stroke: { curve: 'smooth', width: 2.5 }, dataLabels: { enabled: false },
                grid: { borderColor: '#e9ecef', strokeDashArray: 3 },
                xaxis: { type: 'datetime', range: VIVO_PUNTOS * 1000, labels: { datetimeUTC: false, format: 'HH:mm:ss', style: { colors: GRIS } } },
                yaxis: { min: 0, max: max, tickAmount: 5, labels: { formatter: function (v) { return Math.round(v) + ' ' + unidad; } } },
                title: { text: nombre, style: { fontSize: '13px', fontWeight: 600 } },
                tooltip: { x: { format: 'HH:mm:ss' }, y: { formatter: function (v) { return num(v, 1) + ' ' + unidad; } } },
                legend: { show: false },
                annotations: unidad === '°C' ? { yaxis: [{ y: UMBRAL, borderColor: ROJO, strokeDashArray: 6 }] } : {}
            });
            c.render();
            return c;
        }
        var cTemp = chartVivo('[data-tel-chart="vivo_temp"]', 'Temperatura', AMBAR, 100, '°C');
        var cRam  = chartVivo('[data-tel-chart="vivo_ram"]', 'RAM', AZUL, 100, '%');

        function estado(texto, clase) {
            textoVivo.textContent = texto;
            btnVivo.classList.remove('is-vivo', 'is-pausado', 'is-sin-datos');
            if (clase) btnVivo.classList.add(clase);
        }

        function pedirVivo() {
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
        }

        function empezar() {
            if (pausado || document.hidden || renovar) return;
            pedirVivo();
            renovar = setInterval(pedirVivo, VIVO_SEG * 500);
        }
        function frenar() {
            clearInterval(renovar); renovar = null;
            clearTimeout(sinDatos);
        }

        cliente = window.mqtt.connect(raiz.dataset.mqttUrl, {
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
            panelVivo.hidden = false;
            var t = m.ts ? m.ts * 1000 : Date.now();
            if (m.temperatura_soc_celsius !== null && m.temperatura_soc_celsius !== undefined) puntos.temp.push([t, m.temperatura_soc_celsius]);
            if (m.uso_ram_porcentaje !== null && m.uso_ram_porcentaje !== undefined) puntos.ram.push([t, m.uso_ram_porcentaje]);
            puntos.temp = puntos.temp.slice(-VIVO_PUNTOS);
            puntos.ram = puntos.ram.slice(-VIVO_PUNTOS);
            cTemp.updateSeries([{ name: 'Temperatura', data: puntos.temp }]);
            cRam.updateSeries([{ name: 'RAM', data: puntos.ram }]);
            indicadores(m.temperatura_soc_celsius, m.uso_ram_porcentaje);
            raiz.querySelector('[data-tel-ultima]').textContent = 'En vivo · ' + new Date(t).toLocaleTimeString('es-AR') + '.';
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

    // ── Exportar a Excel ────────────────────────────────────────────────────
    var form = raiz.querySelector('[data-tel-exportar]');
    if (form) {
        var desde = form.querySelector('[name="desde"]'), hasta = form.querySelector('[name="hasta"]');
        var btn = form.querySelector('[data-tel-exportar-btn]'), errorGeneral = form.querySelector('[data-tel-exportar-error]');
        function local(d) {
            function dd(n) { return String(n).padStart(2, '0'); }
            return d.getFullYear() + '-' + dd(d.getMonth() + 1) + '-' + dd(d.getDate()) + 'T' + dd(d.getHours()) + ':' + dd(d.getMinutes());
        }
        var ahora = new Date(); ahora.setMinutes(0, 0, 0);
        var dias = parseInt(raiz.dataset.exportDias, 10) || 60;
        hasta.value = local(ahora);
        desde.value = local(new Date(ahora.getTime() - 24 * 3600 * 1000));
        var minimo = new Date(ahora.getTime() - dias * 24 * 3600 * 1000);
        desde.min = hasta.min = local(minimo);

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            errorGeneral.hidden = true;
            [desde, hasta].forEach(function (c) { window.spui.limpiarErrorDeCampo(c); });
            var textoBtn = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Generando…';
            var url = raiz.dataset.urlExportar + '?desde=' + encodeURIComponent(desde.value) + '&hasta=' + encodeURIComponent(hasta.value);
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) {
                    if (r.status === 422) {
                        return r.json().then(function (j) {
                            Object.keys(j.errores || {}).forEach(function (k) {
                                var c = form.querySelector('[name="' + k + '"]');
                                if (c) window.spui.errorDeCampo(c, j.errores[k]);
                            });
                        });
                    }
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    var disp = r.headers.get('Content-Disposition') || '';
                    var nombre = (disp.match(/filename="?([^";]+)"?/) || [])[1] || 'telemetria.xlsx';
                    return r.blob().then(function (b) {
                        var a = document.createElement('a');
                        a.href = URL.createObjectURL(b);
                        a.download = nombre;
                        document.body.appendChild(a);
                        a.click();
                        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
                    });
                })
                .catch(function () {
                    errorGeneral.textContent = 'No se pudo generar el archivo. Revisá la conexión y probá de nuevo.';
                    errorGeneral.hidden = false;
                })
                .then(function () { btn.disabled = false; btn.textContent = textoBtn; });
        });
    }
}());
