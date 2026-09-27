/* Tomar asistencia: puntos en vivo, atajos con deshacer, buscador y aviso de cambios sin guardar. */
(function () {
    var dateForm = document.getElementById('form-fecha');
    if (dateForm) {
        // Al elegir otra fecha se recarga la pantalla con esa fecha.
        var fechaInput = dateForm.querySelector('input[type=date]');
        fechaInput.addEventListener('change', function () { if (fechaInput.value) dateForm.submit(); });
    }

    var form = document.getElementById('form-asistencia');
    if (!form) return;

    var $ = function (id) { return document.getElementById(id); };
    var rows = Array.prototype.slice.call(form.querySelectorAll('.ar-row'));
    var total = rows.length;
    var tema = form.querySelector('input[name=tema]');
    var enviando = false;
    var ultimoUndo = null;
    var CATS = ['completo', 'catequesis', 'misa', 'ausente'];

    var norm = function (s) { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };
    var bloqueada = function () { return form.classList.contains('is-locked'); };

    function radioMarcado(row) { return row.querySelector('input[type=radio]:checked'); }
    function valor(row) { var c = radioMarcado(row); return c ? c.value : ''; }
    function fijar(row, v) {
        row.querySelectorAll('input[type=radio]').forEach(function (r) { r.checked = (v !== '' && r.value === v); });
    }
    function fmt(n) { return (Math.round(n * 10) / 10).toString().replace('.', ','); }

    var temporizadorLive = null;
    function anunciar(texto) {
        clearTimeout(temporizadorLive);
        temporizadorLive = setTimeout(function () { $('live').textContent = texto; }, 600);
    }

    function recalcular() {
        var puntos = 0, marcados = 0, cambios = 0;
        var cuenta = { completo: 0, catequesis: 0, misa: 0, ausente: 0 };

        rows.forEach(function (r) {
            var c = radioMarcado(r);
            var v = c ? c.value : '';
            if (c) { marcados++; puntos += parseFloat(c.dataset.puntos); cuenta[v]++; }
            var cambio = v !== r.dataset.orig;
            if (cambio) cambios++;
            r.classList.toggle('changed', cambio);
            r.classList.toggle('is-no', v === 'ausente');
            r.querySelector('.tag-sin').hidden = v !== '';
        });
        if (tema && tema.value !== tema.defaultValue) cambios++;

        var pct = total ? Math.round((puntos / total) * 100) : 0;
        $('pts-total').textContent = fmt(puntos);
        $('pts-pct').textContent = pct + '%';
        $('pts-marcados').textContent = 'Marcados ' + marcados + ' de ' + total;
        CATS.forEach(function (c) { $('cnt-' + c).textContent = cuenta[c]; });

        var fill = $('progress-fill');
        fill.style.width = pct + '%';
        fill.className = 'progress-fill ' + (marcados === 0 ? '' : (pct >= 75 ? 'p-ok' : (pct >= 40 ? 'p-mid' : 'p-low')));
        $('mini-fill').style.width = (total ? Math.round((marcados / total) * 100) : 0) + '%';

        var estado = $('save-status');
        if (bloqueada()) {
            estado.textContent = '✓ Guardada · solo lectura';
            estado.className = 'save-status clean';
        } else if (cambios > 0) {
            estado.textContent = cambios + (cambios === 1 ? ' cambio sin guardar' : ' cambios sin guardar');
            estado.className = 'save-status dirty';
        } else if (marcados === 0) {
            estado.textContent = 'Sin marcar todavía';
            estado.className = 'save-status';
        } else {
            estado.textContent = form.hasAttribute('data-lock') ? 'Sin cambios' : '✓ Todo guardado';
            estado.className = 'save-status clean';
        }
        $('btn-guardar').disabled = cambios === 0;
        anunciar(fmt(puntos) + ' de ' + total + ' puntos. ' + (bloqueada() ? 'Asistencia guardada.' : (cambios > 0 ? estado.textContent + '.' : '')));
    }

    form.addEventListener('change', function (e) {
        if (e.target.type === 'radio') {
            ultimoUndo = null;
            $('btn-deshacer').disabled = true;
            recalcular();
        }
    });
    if (tema) tema.addEventListener('input', recalcular);

    // Al volver al modo protegido se descartan los cambios: cada fila vuelve a lo guardado.
    form.addEventListener('bloqueo', function (e) {
        if (e.detail.bloqueado) {
            rows.forEach(function (r) { fijar(r, r.dataset.orig); });
            ultimoUndo = null;
            $('btn-deshacer').disabled = true;
        }
        recalcular();
    });

    // ---------- Atajos (solo afectan a las filas visibles) con "Deshacer" ----------
    function visibles() { return rows.filter(function (r) { return !r.hidden; }); }

    function deshacer() {
        if (!ultimoUndo || bloqueada()) return;
        ultimoUndo.forEach(function (p) { fijar(p[0], p[1]); });
        ultimoUndo = null;
        $('btn-deshacer').disabled = true;
        recalcular();
    }

    function accionMasiva(tipo) {
        if (bloqueada()) return;
        var afectadas = visibles();
        ultimoUndo = afectadas.map(function (r) { return [r, valor(r)]; });
        afectadas.forEach(function (r) { fijar(r, tipo === 'limpiar' ? '' : tipo); });
        $('btn-deshacer').disabled = false;
        recalcular();

        var msgs = { completo: 'Marcados como "Misa y Catequesis"', ausente: 'Marcados como "No asistió"', limpiar: 'Marcas limpiadas' };
        window.toast(msgs[tipo] + ' (' + afectadas.length + ')', 'success', { accion: { texto: 'Deshacer', fn: deshacer } });
    }
    form.querySelectorAll('[data-bulk]').forEach(function (b) {
        b.addEventListener('click', function () { accionMasiva(b.dataset.bulk); });
    });
    $('btn-deshacer').addEventListener('click', deshacer);

    // ---------- Buscador ----------
    var buscador = $('buscar-asistencia');
    buscador.addEventListener('input', function () {
        var q = norm(buscador.value.trim());
        var n = 0;
        rows.forEach(function (r) {
            var ok = !q || norm(r.dataset.nombre).indexOf(q) !== -1;
            r.hidden = !ok;
            if (ok) n++;
        });
        $('sin-resultados').hidden = n > 0;
    });

    // ---------- Guardar y salir ----------
    form.addEventListener('submit', function (e) {
        if (bloqueada()) { e.preventDefault(); return; }
        var sinMarcar = rows.filter(function (r) { return valor(r) === ''; }).length;
        if (sinMarcar > 0 && !confirm('Faltan ' + sinMarcar + ' catequizando' + (sinMarcar === 1 ? '' : 's') + ' sin marcar. Al guardar quedarán sin registro de asistencia (si ya tenían una marca, se quita). ¿Guardar de todos modos?')) {
            e.preventDefault();
            return;
        }
        enviando = true;
        var b = $('btn-guardar');
        b.disabled = true;
        b.textContent = 'Guardando…';
    });
    window.addEventListener('beforeunload', function (e) {
        var sucio = rows.some(function (r) { return valor(r) !== r.dataset.orig; }) || (tema && tema.value !== tema.defaultValue);
        if (!enviando && !bloqueada() && sucio) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) {
            enviando = false;
            $('btn-guardar').textContent = 'Guardar asistencia';
            recalcular();
        }
    });

    recalcular();
})();
