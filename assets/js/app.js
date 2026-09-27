/* Comportamiento global: avisos tipo "toast" en lugar de banners fijos. */
(function () {
    var wrap = document.createElement('div');
    wrap.className = 'toast-wrap';
    wrap.setAttribute('role', 'status');
    wrap.setAttribute('aria-live', 'polite');
    wrap.setAttribute('aria-atomic', 'true');
    document.body.appendChild(wrap);

    /**
     * toast(mensaje, tipo, opciones)
     *  tipo: 'success' (se cierra sola) | 'error' (queda hasta cerrarla, se anuncia como alerta)
     *  opciones.accion = { texto: 'Deshacer', fn: function () {} }   (dura más y se pausa al usarla)
     */
    window.toast = function (mensaje, tipo, opciones) {
        opciones = opciones || {};
        var el = document.createElement('div');
        el.className = 'toast' + (tipo === 'error' ? ' toast-error' : '');
        if (tipo === 'error') {
            el.setAttribute('role', 'alert');
        }

        var msg = document.createElement('span');
        msg.className = 'toast-msg';
        msg.textContent = mensaje;
        el.appendChild(msg);

        var temporizador = null;
        function cerrar() {
            clearTimeout(temporizador);
            if (el.parentNode) {
                el.parentNode.removeChild(el);
            }
        }

        if (opciones.accion) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'toast-btn';
            b.textContent = opciones.accion.texto;
            b.addEventListener('click', function () {
                opciones.accion.fn();
                cerrar();
            });
            el.appendChild(b);
        }

        var x = document.createElement('button');
        x.type = 'button';
        x.className = 'toast-x';
        x.setAttribute('aria-label', 'Cerrar aviso');
        x.textContent = '×';
        x.addEventListener('click', cerrar);
        el.appendChild(x);

        wrap.appendChild(el);

        // Con acción (Deshacer) dura 20 s; los éxitos simples 5 s; los errores no se cierran solos.
        var ms = opciones.accion ? 20000 : (tipo === 'error' ? 0 : 5000);
        function programar() {
            clearTimeout(temporizador);
            if (ms > 0) {
                temporizador = setTimeout(cerrar, ms);
            }
        }
        // El aviso no desaparece mientras se lee o se usa con el mouse o el teclado.
        el.addEventListener('mouseenter', function () { clearTimeout(temporizador); });
        el.addEventListener('focusin', function () { clearTimeout(temporizador); });
        el.addEventListener('mouseleave', programar);
        el.addEventListener('focusout', programar);
        programar();
        return el;
    };

    // Los avisos que deja el servidor (guardado, errores...) se muestran como toast.
    // Se muestran con un pequeño retraso: una región "viva" recién creada no anuncia su contenido inicial.
    var avisos = [];
    document.querySelectorAll('main .flash').forEach(function (f) {
        var texto = f.textContent.replace(/\s+/g, ' ').trim();
        if (texto) {
            avisos.push({ texto: texto, error: f.classList.contains('error') });
        }
        f.parentNode.removeChild(f);
    });
    if (avisos.length) {
        setTimeout(function () {
            avisos.forEach(function (a) { window.toast(a.texto, a.error ? 'error' : 'success'); });
        }, 300);
    }
})();
