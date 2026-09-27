/* Tomar asistencia de una actividad: marcado rápido, atajos con deshacer, buscador y aviso de cambios sin guardar. */
(function () {
    var form = document.getElementById('form-actividad-asistencia');
    if (!form) return;

    var $ = function (id) { return document.getElementById(id); };
    var rows = Array.prototype.slice.call(form.querySelectorAll('.ar-row'));
    var total = rows.length;
    var enviando = false;
    var ultimoUndo = null;   // instantánea de la última acción masiva

    var bloqueada = function () { return form.classList.contains('is-locked'); };
    var norm = function (s) { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };

    function valor(row) {
        var c = row.querySelector('input[type=radio]:checked');
        return c ? c.value : '';
    }
    function fijar(row, v) {
        row.querySelectorAll('input[type=radio]').forEach(function (r) { r.checked = (v !== '' && r.value === v); });
    }

    var temporizadorLive = null;
    function anunciar(texto) {
        clearTimeout(temporizadorLive);
        temporizadorLive = setTimeout(function () { $('live').textContent = texto; }, 600);
    }

    function recalcular() {
        var si = 0, marcados = 0, cambios = 0;
        rows.forEach(function (r) {
            var v = valor(r);
            if (v !== '') marcados++;
            if (v === '1') si++;
            var cambio = v !== r.dataset.orig;
            if (cambio) cambios++;
            r.classList.toggle('changed', cambio);
            r.classList.toggle('is-no', v === '0');
            r.querySelector('.tag-sin').hidden = v !== '';
        });

        var pct = total ? Math.round((si / total) * 100) : 0;
        $('asistio-si').textContent = si;
        $('asistio-pct').textContent = pct + '%';
        $('asistio-marcados').textContent = 'Marcados ' + marcados + ' de ' + total;

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
        anunciar(si + ' de ' + total + ' asistieron. ' + (bloqueada() ? 'Asistencia guardada.' : (cambios > 0 ? estado.textContent + '.' : (marcados === 0 ? 'Sin marcar.' : 'Todo guardado.'))));
    }

    // Un cambio hecho a mano invalida el "deshacer" de la última acción masiva.
    form.addEventListener('change', function (e) {
        if (e.target.type === 'radio') {
            ultimoUndo = null;
            $('btn-deshacer').disabled = true;
            recalcular();
        }
    });

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
        var antes = afectadas.map(function (r) { return [r, valor(r)]; });

        afectadas.forEach(function (r) {
            var v = valor(r);
            if (tipo === 'si') fijar(r, '1');
            else if (tipo === 'no') fijar(r, '0');
            else if (tipo === 'limpiar') fijar(r, '');
            else if (tipo === 'invertir' && v !== '') fijar(r, v === '1' ? '0' : '1');
        });
        ultimoUndo = antes;
        $('btn-deshacer').disabled = false;
        recalcular();

        var msgs = { si: 'Marcados como "Asistió"', no: 'Marcados como "No asistió"', invertir: 'Selección invertida', limpiar: 'Marcas limpiadas' };
        window.toast(msgs[tipo] + ' (' + afectadas.length + ')', 'success', {
            accion: { texto: 'Deshacer', fn: deshacer }
        });
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

    // ---------- Guardar: avisa si faltan marcar, y avisa al salir con cambios ----------
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
        if (!enviando && !bloqueada() && rows.some(function (r) { return valor(r) !== r.dataset.orig; })) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
    // Si el navegador restaura la página desde su memoria (botón Atrás), el botón no debe quedar en "Guardando…".
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) {
            enviando = false;
            $('btn-guardar').textContent = 'Guardar asistencia';
            recalcular();
        }
    });

    recalcular();
})();
