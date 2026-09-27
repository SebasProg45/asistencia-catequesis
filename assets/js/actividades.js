/* Módulo Actividades: pestañas, búsqueda, filtro por grupo, orden, diálogos crear/editar/eliminar. */
(function () {
    var $ = function (s, ctx) { return (ctx || document).querySelector(s); };
    var $$ = function (s, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(s)); };
    var norm = function (s) { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };

    var tabs = $$('.tab');
    var vistas = $$('[data-vista]');
    var buscar = $('#buscar');
    var fGrupo = $('#filtro-grupo');
    var orden = $('#orden-part');
    var tabInicial = window.ACT_TAB_INICIAL || 'proximas';
    var TABS_VALIDAS = ['proximas', 'pasadas', 'personas'];

    // ---------- Estado de la vista (vive en la URL) ----------
    var p = new URLSearchParams(location.search);
    var estado = {
        tab: TABS_VALIDAS.indexOf(p.get('tab')) >= 0 ? p.get('tab') : tabInicial,
        q: p.get('q') || '',
        grupo: p.get('grupo') || '',
        orden: p.get('orden') || 'nombre'
    };

    function guardarUrl() {
        var u = new URLSearchParams();
        if (estado.tab !== tabInicial) u.set('tab', estado.tab);
        if (estado.q) u.set('q', estado.q);
        if (estado.grupo) u.set('grupo', estado.grupo);
        if (estado.orden !== 'nombre') u.set('orden', estado.orden);
        var s = u.toString();
        history.replaceState(null, '', location.pathname + (s ? '?' + s : ''));
    }

    function ordenarPersonas() {
        var ul = $('#lista-part');
        if (!ul) return;
        var items = $$('.part-item', ul);
        items.sort(function (a, b) {
            var pa = +a.dataset.pct, pb = +b.dataset.pct;
            if (estado.orden !== 'nombre') {
                var sinA = pa < 0, sinB = pb < 0;
                if (sinA !== sinB) return sinA ? 1 : -1;          // sin actividades siempre al final
                if (pa !== pb) return estado.orden === 'mas' ? pb - pa : pa - pb;
                if (+a.dataset.si !== +b.dataset.si) return estado.orden === 'mas' ? +b.dataset.si - +a.dataset.si : +a.dataset.si - +b.dataset.si;
            }
            return a.dataset.nombre.localeCompare(b.dataset.nombre, 'es');
        });
        items.forEach(function (li) { ul.appendChild(li); });
    }

    var live = $('#live-act');
    var temporizadorLive = null;
    function anunciar(vista, n) {
        if (!live || !vista) return;
        clearTimeout(temporizadorLive);
        temporizadorLive = setTimeout(function () {
            var tipo = vista.dataset.vista === 'personas' ? 'catequizando' : 'actividad';
            live.textContent = n === 0 ? 'Sin resultados.' : n + ' ' + tipo + (n === 1 ? '' : (tipo === 'actividad' ? 'es' : 's')) + ' mostrad' + (n === 1 ? 'a' : 'as') + '.';
        }, 400);
    }

    function aplicar() {
        tabs.forEach(function (t) {
            var sel = t.dataset.tab === estado.tab;
            t.setAttribute('aria-selected', sel ? 'true' : 'false');
            t.tabIndex = sel ? 0 : -1;                       // solo la pestaña activa entra en el orden de tabulación
        });
        vistas.forEach(function (v) { v.hidden = v.dataset.vista !== estado.tab; });

        var q = norm(estado.q);
        $$('.act-card, .part-item').forEach(function (el) {
            var okGrupo = !estado.grupo || el.dataset.grupo === estado.grupo;
            var okTexto = !q || norm(el.dataset.q).indexOf(q) !== -1;
            el.hidden = !(okGrupo && okTexto);
        });
        var activa = null, visiblesActiva = 0;
        vistas.forEach(function (v) {
            var visibles = $$('.act-card, .part-item', v).filter(function (i) { return !i.hidden; }).length;
            var vacio = $('[data-vacio]', v);
            if (vacio) vacio.hidden = visibles > 0;
            if (!v.hidden) { activa = v; visiblesActiva = visibles; }
        });
        anunciar(activa, visiblesActiva);

        ordenarPersonas();
        if (buscar && buscar.value !== estado.q) buscar.value = estado.q;
        if (fGrupo && fGrupo.value !== estado.grupo) fGrupo.value = estado.grupo;
        if (orden && orden.value !== estado.orden) orden.value = estado.orden;
        guardarUrl();
    }

    tabs.forEach(function (t) {
        t.addEventListener('click', function () { estado.tab = t.dataset.tab; aplicar(); });
        t.addEventListener('keydown', function (e) {                    // flechas, Inicio y Fin
            var i = tabs.indexOf(t), destino = null;
            if (e.key === 'ArrowRight') destino = tabs[(i + 1) % tabs.length];
            else if (e.key === 'ArrowLeft') destino = tabs[(i + tabs.length - 1) % tabs.length];
            else if (e.key === 'Home') destino = tabs[0];
            else if (e.key === 'End') destino = tabs[tabs.length - 1];
            if (destino) { e.preventDefault(); estado.tab = destino.dataset.tab; aplicar(); destino.focus(); }
        });
    });
    if (buscar) buscar.addEventListener('input', function () { estado.q = buscar.value.trim(); aplicar(); });
    if (fGrupo) fGrupo.addEventListener('change', function () { estado.grupo = fGrupo.value; aplicar(); });
    if (orden) orden.addEventListener('change', function () { estado.orden = orden.value; aplicar(); });
    if (vistas.length) aplicar();

    // ---------- Diálogo crear / editar ----------
    var dlg = $('#dlg-actividad');
    var form = $('#form-actividad');

    function abrir(d) {
        if (typeof d.showModal === 'function') d.showModal(); else d.setAttribute('open', '');
    }
    function cerrar(d) {
        if (typeof d.close === 'function') d.close(); else d.removeAttribute('open');
    }
    function hoyLocal() {
        var d = new Date();
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    }
    function limpiarErrores() {
        $$('.field-err', form).forEach(function (s) { s.textContent = ''; });
        $$('.invalid', form).forEach(function (i) { i.classList.remove('invalid'); i.removeAttribute('aria-invalid'); });
    }
    function grupoPorDefecto() {
        var sel = form.elements.grupo_id;
        var opciones = $$('option', sel).map(function (o) { return o.value; }).filter(Boolean);
        var guardado = '';
        try { guardado = localStorage.getItem('act_grupo') || ''; } catch (e) { /* sin almacenamiento */ }
        if (estado.grupo && opciones.indexOf(estado.grupo) >= 0) return estado.grupo;
        if (guardado && opciones.indexOf(guardado) >= 0) return guardado;
        return opciones.length === 1 ? opciones[0] : '';
    }

    function abrirNueva() {
        limpiarErrores();
        form.reset();
        form.elements.accion.value = 'crear';
        form.elements.id.value = '';
        form.elements.volver_a.value = location.search;
        form.elements.fecha.value = hoyLocal();
        form.elements.grupo_id.value = grupoPorDefecto();
        $('#dlg-titulo').textContent = 'Nueva actividad';
        $$('[data-solo-crear]', form).forEach(function (b) { b.hidden = false; });
        abrir(dlg);
        form.elements.nombre.focus();
    }
    function abrirEditar(btn) {
        limpiarErrores();
        form.reset();
        form.elements.accion.value = 'editar';
        form.elements.id.value = btn.dataset.id;
        form.elements.volver_a.value = location.search;
        form.elements.nombre.value = btn.dataset.nombre;
        form.elements.fecha.value = btn.dataset.fecha;
        form.elements.grupo_id.value = btn.dataset.grupo;
        form.elements.descripcion.value = btn.dataset.desc || '';
        $('#dlg-titulo').textContent = 'Editar actividad';
        $$('[data-solo-crear]', form).forEach(function (b) { b.hidden = true; });
        abrir(dlg);
        form.elements.nombre.focus();
    }

    // Validación en línea con mensajes en español
    var MENSAJES = { nombre: 'Escribe un nombre para la actividad.', fecha: 'Elige la fecha.', grupo_id: 'Elige el grupo.' };
    function marcar(campo, mal) {
        var el = form.elements[campo];
        el.classList.toggle('invalid', mal);
        el.setAttribute('aria-invalid', mal ? 'true' : 'false');
        var err = $('[data-err="' + campo + '"]', form);
        if (err) err.textContent = mal ? MENSAJES[campo] : '';
        return mal;
    }
    function invalido(campo) {
        var v = form.elements[campo].value;
        return campo === 'nombre' ? v.trim() === '' : v === '';
    }
    ['nombre', 'fecha', 'grupo_id'].forEach(function (c) {
        form.elements[c].addEventListener('blur', function () { marcar(c, invalido(c)); });
        form.elements[c].addEventListener('input', function () { if (form.elements[c].classList.contains('invalid')) marcar(c, invalido(c)); });
    });
    form.addEventListener('submit', function (e) {
        var primero = null;
        ['nombre', 'fecha', 'grupo_id'].forEach(function (c) {
            if (marcar(c, invalido(c)) && !primero) primero = c;
        });
        if (primero) {
            e.preventDefault();
            form.elements[primero].focus();
            return;
        }
        try { localStorage.setItem('act_grupo', form.elements.grupo_id.value); } catch (err) { /* ignorar */ }
    });

    // ---------- Diálogo eliminar ----------
    var dlgEl = $('#dlg-eliminar');
    var formEl = $('#form-eliminar');
    var check = $('#el-check');
    var botonEl = $('#el-boton');

    function abrirEliminar(btn) {
        var n = parseInt(btn.dataset.registrados, 10) || 0;
        formEl.elements.id.value = btn.dataset.id;
        formEl.elements.volver_a.value = location.search;
        $('#el-texto').textContent = n > 0
            ? 'Vas a eliminar «' + btn.dataset.nombre + '» (' + btn.dataset.fecha + '). Se perderá la asistencia registrada de ' + n + ' catequizando' + (n === 1 ? '' : 's') + '. Esta acción no se puede deshacer.'
            : 'Vas a eliminar «' + btn.dataset.nombre + '» (' + btn.dataset.fecha + '). No tiene asistencia registrada.';
        $('#el-confirma').hidden = n === 0;
        check.checked = false;
        botonEl.disabled = n > 0;
        abrir(dlgEl);
    }
    check.addEventListener('change', function () { botonEl.disabled = !check.checked; });

    // ---------- Botones (delegación) ----------
    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-nueva], [data-editar], [data-eliminar], [data-cerrar]');
        if (!t) return;
        if (t.hasAttribute('data-nueva')) abrirNueva();
        else if (t.hasAttribute('data-editar')) abrirEditar(t);
        else if (t.hasAttribute('data-eliminar')) abrirEliminar(t);
        else if (t.hasAttribute('data-cerrar')) cerrar(t.closest('dialog'));
    });
    [dlg, dlgEl].forEach(function (d) {
        d.addEventListener('click', function (e) { if (e.target === d) cerrar(d); });   // clic en el fondo cierra
    });
})();
