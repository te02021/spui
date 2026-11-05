// Listas desplegables propias de los formularios (05/11/2026): el navegador no deja
// darle estilo a las opciones de un <select>. El <select> real sigue ahí, invisible:
// es el que viaja en el formulario y el que revisa validacion-vivo.js.
//
// Se aplica solo a los select.unraf-form-select dentro de un .spui-form. Con más de
// OPCIONES_PARA_BUSCAR opciones suma un buscador. "Nombre — Lugar" se muestra en dos
// niveles. La lista va en <body>, porque el modal la recortaría.
(function () {
    'use strict';

    var OPCIONES_PARA_BUSCAR = 8;
    var abierto = null;   // { select, btn, panel, lista, buscador, activo }

    function icono(nombre) { return (window.spui && window.spui.iconos && window.spui.iconos[nombre]) || ''; }

    function normalizar(t) { return (t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); }

    /** "— elegí una playlist —" → "Elegí una playlist". */
    function textoVacio(t) {
        t = (t || '').replace(/^[—\s]+|[—\s]+$/g, '');
        return t.charAt(0).toUpperCase() + t.slice(1);
    }

    function partes(t) {
        var i = t.indexOf(' — ');
        return i === -1 ? [t, ''] : [t.slice(0, i), t.slice(i + 3)];
    }

    function pintarBoton(select) {
        var op = select.options[select.selectedIndex];
        var vacio = !op || op.value === '';
        var el = select._spuiBtn.querySelector('.spui-select-texto');
        el.textContent = vacio ? textoVacio(op ? op.text : '') : partes(op.text)[0];
        el.classList.toggle('is-vacio', vacio);
        var detalle = select._spuiBtn.querySelector('.spui-select-detalle');
        detalle.textContent = vacio ? '' : partes(op.text)[1];
        detalle.hidden = vacio || !detalle.textContent;
    }

    function mejorar(select) {
        if (select._spuiBtn) return;
        var cont = document.createElement('div');
        cont.className = 'spui-select';
        select.parentNode.insertBefore(cont, select);

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'unraf-form-select spui-select-btn';
        btn.id = select.id + '_btn';
        btn.setAttribute('role', 'combobox');
        btn.setAttribute('aria-haspopup', 'listbox');
        btn.setAttribute('aria-expanded', 'false');
        btn.innerHTML = '<span class="spui-select-texto"></span><span class="spui-select-detalle" hidden></span>';
        cont.appendChild(btn);
        cont.appendChild(select);

        select.classList.add('spui-select-nativo');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');
        select._spuiBtn = btn;

        // La etiqueta y el foco que da validacion-vivo.js van al botón.
        var etiqueta = document.querySelector('label[for="' + select.id + '"]');
        if (etiqueta) { etiqueta.htmlFor = btn.id; }
        select.addEventListener('focus', function () { btn.focus(); });
        btn.addEventListener('click', function () { if (abierto && abierto.select === select) cerrar(true); else abrir(select); });
        btn.addEventListener('keydown', function (e) {
            if (abierto) return;
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                abrir(select);
            }
        });
        pintarBoton(select);
    }

    function opciones() {
        return Array.prototype.filter.call(abierto.lista.children, function (li) { return !li.hidden; });
    }

    function marcarActivo(li) {
        if (abierto.activo) abierto.activo.classList.remove('is-activo');
        abierto.activo = li || null;
        if (!li) return;
        li.classList.add('is-activo');
        li.scrollIntoView({ block: 'nearest' });
        (abierto.buscador || abierto.btn).setAttribute('aria-activedescendant', li.id);
    }

    function ubicar() {
        var r = abierto.btn.getBoundingClientRect();
        var p = abierto.panel;
        var ancho = Math.max(r.width, 220);
        p.style.width = Math.min(ancho, window.innerWidth - 16) + 'px';
        p.style.left = Math.max(8, Math.min(r.left, window.innerWidth - ancho - 8)) + 'px';
        var abajo = window.innerHeight - r.bottom - 12;
        var arriba = r.top - 12;
        var alto = p.scrollHeight;
        if (abajo >= Math.min(alto, 220) || abajo >= arriba) {
            p.style.top = (r.bottom + 4) + 'px';
            p.style.bottom = 'auto';
            p.style.maxHeight = abajo + 'px';
        } else {
            p.style.top = 'auto';
            p.style.bottom = (window.innerHeight - r.top + 4) + 'px';
            p.style.maxHeight = arriba + 'px';
        }
    }

    function abrir(select) {
        cerrar(false);
        var btn = select._spuiBtn;
        var panel = document.createElement('div');
        panel.className = 'spui-select-panel';

        var buscador = null;
        var cantidad = Array.prototype.filter.call(select.options, function (o) { return o.value !== ''; }).length;
        if (cantidad > OPCIONES_PARA_BUSCAR) {
            buscador = document.createElement('input');
            buscador.type = 'search';
            buscador.className = 'spui-select-buscar';
            buscador.placeholder = 'Buscar…';
            buscador.setAttribute('aria-label', 'Buscar en la lista');
            panel.appendChild(buscador);
        }
        var lista = document.createElement('ul');
        lista.className = 'spui-select-lista';
        lista.id = select.id + '_lista';
        lista.setAttribute('role', 'listbox');
        panel.appendChild(lista);

        Array.prototype.forEach.call(select.options, function (o, i) {
            // El "elegí uno" de una lista obligatoria no es una opción más.
            if (o.value === '' && select.required) return;
            var li = document.createElement('li');
            li.id = select.id + '_op' + i;
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', o.selected ? 'true' : 'false');
            li.dataset.valor = o.value;
            li.dataset.buscar = normalizar(o.text);
            var p = partes(o.text);
            var txt = document.createElement('span');
            txt.className = 'spui-select-op-texto';
            txt.textContent = o.value === '' ? textoVacio(o.text) : p[0];
            if (o.value === '') txt.classList.add('is-vacio');
            li.appendChild(txt);
            if (p[1] && o.value !== '') {
                var sub = document.createElement('small');
                sub.textContent = p[1];
                txt.appendChild(sub);
            }
            if (o.selected && o.value !== '') {
                var check = document.createElement('img');
                check.src = icono('check');
                check.alt = '';
                li.appendChild(check);
            }
            lista.appendChild(li);
        });

        var vacio = document.createElement('p');
        vacio.className = 'spui-select-vacio';
        vacio.textContent = 'No hay coincidencias.';
        vacio.hidden = lista.children.length > 0;
        if (!lista.children.length) vacio.textContent = 'No hay opciones cargadas.';
        panel.appendChild(vacio);

        document.body.appendChild(panel);
        abierto = { select: select, btn: btn, panel: panel, lista: lista, buscador: buscador, vacio: vacio, activo: null };
        btn.setAttribute('aria-expanded', 'true');
        btn.setAttribute('aria-controls', lista.id);
        ubicar();

        var elegido = lista.querySelector('[aria-selected="true"]') || lista.firstElementChild;
        marcarActivo(elegido);
        if (buscador) {
            buscador.focus();
            buscador.addEventListener('input', filtrar);
        }
        panel.addEventListener('mousedown', function (e) { if (e.target !== buscador) e.preventDefault(); });
        lista.addEventListener('click', function (e) {
            var li = e.target.closest('li');
            if (li) elegir(li);
        });
        lista.addEventListener('mousemove', function (e) {
            var li = e.target.closest('li');
            if (li && li !== abierto.activo) marcarActivo(li);
        });
    }

    function filtrar() {
        var q = normalizar(abierto.buscador.value.trim());
        Array.prototype.forEach.call(abierto.lista.children, function (li) {
            li.hidden = q !== '' && li.dataset.buscar.indexOf(q) === -1;
        });
        var quedan = opciones();
        abierto.vacio.textContent = 'No hay coincidencias.';
        abierto.vacio.hidden = quedan.length > 0;
        marcarActivo(quedan[0]);
    }

    function elegir(li) {
        var select = abierto.select;
        select.value = li.dataset.valor;
        cerrar(true);
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function cerrar(devolverFoco) {
        if (!abierto) return;
        var a = abierto;
        abierto = null;
        a.panel.remove();
        a.btn.setAttribute('aria-expanded', 'false');
        a.btn.removeAttribute('aria-controls');
        a.btn.removeAttribute('aria-activedescendant');
        if (devolverFoco) a.btn.focus();
    }

    function iniciar(raiz) {
        cerrar(false);
        // El de "Como en…" de la vista previa es otra cosa: va chico y se redibuja solo.
        (raiz || document).querySelectorAll('.spui-form select.unraf-form-select:not([data-spui-tv-edificio])').forEach(mejorar);
    }

    document.addEventListener('DOMContentLoaded', function () { iniciar(); });
    document.addEventListener('spui:modal:loaded', function (e) { iniciar(e.target); });

    // También cuando otro script cambia el valor (alcance-campo.js vacía una lista).
    document.addEventListener('change', function (e) {
        if (e.target && e.target._spuiBtn) pintarBoton(e.target);
    });

    // En captura: con la lista abierta, Esc la cierra a ella y no al modal.
    document.addEventListener('keydown', function (e) {
        if (!abierto) return;
        var lista = opciones();
        var i = lista.indexOf(abierto.activo);
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopImmediatePropagation();
            cerrar(true);
        } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            marcarActivo(lista[Math.min(i + 1, lista.length - 1)]);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            marcarActivo(lista[Math.max(i - 1, 0)]);
        } else if (e.key === 'Enter' || (e.key === ' ' && !abierto.buscador)) {
            e.preventDefault();
            if (abierto.activo) elegir(abierto.activo);
        } else if (e.key === 'Tab') {
            cerrar(false);
        }
    }, true);

    document.addEventListener('mousedown', function (e) {
        if (abierto && !abierto.panel.contains(e.target) && !abierto.btn.contains(e.target)) cerrar(false);
    }, true);
    // Al moverse lo de abajo, la lista quedaría despegada de su campo.
    document.addEventListener('scroll', function (e) {
        if (abierto && !abierto.panel.contains(e.target)) cerrar(false);
    }, true);
    window.addEventListener('resize', function () { cerrar(false); });
}());
