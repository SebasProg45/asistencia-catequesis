<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/functions.php';

function fecha_valida(string $f): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $f);
    return $d !== false && $d->format('Y-m-d') === $f;
}

$grupos = $pdo->query('SELECT * FROM grupos ORDER BY nombre')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {
    $grupo_id = (int) ($_POST['grupo_id'] ?? 0);
    $fecha = (string) ($_POST['fecha'] ?? '');
    $tema = mb_substr(trim((string) ($_POST['tema'] ?? '')), 0, 200) ?: null;
    $categoriasPost = is_array($_POST['categoria'] ?? null) ? $_POST['categoria'] : [];

    if ($grupo_id <= 0 || !fecha_valida($fecha)) {
        set_flash('error', 'Selecciona un grupo y una fecha válidos.');
        redirect('asistencia.php');
    }

    // Solo se aceptan catequizandos que pertenecen al grupo de la sesión.
    $stmt = $pdo->prepare('SELECT id FROM estudiantes WHERE grupo_id = ?');
    $stmt->execute([$grupo_id]);
    $idsGrupo = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

    $categoriasValidas = ['completo', 'catequesis', 'misa', 'ausente'];
    $volver = 'asistencia.php?grupo=' . $grupo_id . '&fecha=' . urlencode($fecha);

    $hayMarcas = false;
    foreach ($categoriasPost as $estudiante_id => $valor) {
        if (isset($idsGrupo[(int) $estudiante_id]) && in_array($valor, $categoriasValidas, true)) {
            $hayMarcas = true;
            break;
        }
    }

    $stmt = $pdo->prepare('SELECT id FROM sesiones WHERE grupo_id = ? AND fecha = ?');
    $stmt->execute([$grupo_id, $fecha]);
    $sesionExistente = $stmt->fetchColumn();

    if (!$hayMarcas && !$sesionExistente) {
        set_flash('error', 'Marca la asistencia de al menos un catequizando antes de guardar.');
        redirect($volver);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('
            INSERT INTO sesiones (grupo_id, fecha, tema) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE tema = VALUES(tema)
        ');
        $stmt->execute([$grupo_id, $fecha, $tema]);

        $stmt = $pdo->prepare('SELECT id FROM sesiones WHERE grupo_id = ? AND fecha = ?');
        $stmt->execute([$grupo_id, $fecha]);
        $sesion_id = (int) $stmt->fetchColumn();

        $upsert = $pdo->prepare('
            INSERT INTO asistencias (sesion_id, estudiante_id, categoria) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE categoria = VALUES(categoria)
        ');
        $borrar = $pdo->prepare('DELETE FROM asistencias WHERE sesion_id = ? AND estudiante_id = ?');

        $guardados = 0;
        $quitados = 0;
        foreach ($categoriasPost as $estudiante_id => $valor) {
            $estudiante_id = (int) $estudiante_id;
            if (!isset($idsGrupo[$estudiante_id])) {
                continue;
            }
            if ($valor === '') {
                // Sin marca: si tenía un registro anterior, se elimina (así "Limpiar" se puede guardar).
                $borrar->execute([$sesion_id, $estudiante_id]);
                $quitados += $borrar->rowCount();
                continue;
            }
            if (!in_array($valor, $categoriasValidas, true)) {
                continue;
            }
            $upsert->execute([$sesion_id, $estudiante_id, $valor]);
            $guardados++;
        }

        $pdo->commit();
        set_flash('success', "Asistencia guardada ($guardados catequizandos)."
            . ($quitados > 0 ? " Se quitó la marca a $quitados." : ''));
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'No se pudo guardar la asistencia. Intenta de nuevo.');
    }

    redirect($volver);
}

$hoy = date('Y-m-d');
$grupo_id = (int) ($_GET['grupo'] ?? 0);
$fecha = (string) ($_GET['fecha'] ?? $hoy);
if (!fecha_valida($fecha)) {
    $fecha = $hoy;
}

$grupoActual = null;
foreach ($grupos as $g) {
    if ((int) $g['id'] === $grupo_id) {
        $grupoActual = $g;
    }
}
if (!$grupoActual) {
    $grupo_id = 0;
}

$estudiantes = [];
$sesion = null;
$asistenciasPrevias = [];

if ($grupo_id) {
    $stmt = $pdo->prepare('SELECT * FROM estudiantes WHERE grupo_id = ? ORDER BY nombres, apellidos');
    $stmt->execute([$grupo_id]);
    $estudiantes = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM sesiones WHERE grupo_id = ? AND fecha = ?');
    $stmt->execute([$grupo_id, $fecha]);
    $sesion = $stmt->fetch() ?: null;

    if ($sesion) {
        $stmt = $pdo->prepare('SELECT estudiante_id, categoria FROM asistencias WHERE sesion_id = ?');
        $stmt->execute([$sesion['id']]);
        foreach ($stmt->fetchAll() as $row) {
            $asistenciasPrevias[$row['estudiante_id']] = $row['categoria'];
        }
    }
}

// Con asistencia ya guardada la pantalla se abre protegida: hay que confirmar antes de editar.
$bloqueada = $sesion && $asistenciasPrevias;
$sinMarcarInicial = count(array_filter($estudiantes, fn($es) => !isset($asistenciasPrevias[$es['id']])));

$opcionesAsistencia = [
    ['valor' => 'completo', 'etiqueta' => 'Misa y Catequesis', 'puntos' => '1.0', 'pts' => '1 punto', 'clase' => 'completo', 'icono' => 'tick'],
    ['valor' => 'catequesis', 'etiqueta' => 'Solo Catequesis', 'puntos' => '0.5', 'pts' => '½ punto', 'clase' => 'parcial', 'icono' => 'book'],
    ['valor' => 'misa', 'etiqueta' => 'Solo Misa', 'puntos' => '0.5', 'pts' => '½ punto', 'clase' => 'misa', 'icono' => 'cross'],
    ['valor' => 'ausente', 'etiqueta' => 'No asistió', 'puntos' => '0.0', 'pts' => '0 puntos', 'clase' => 'ausente', 'icono' => 'x'],
];

// Fechas rápidas: hoy y los dos últimos sábados.
$mesesCorto = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
$mesesHero = ['', 'ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];
$corta = fn(string $f) => date('j', strtotime($f)) . ' ' . $mesesCorto[(int) date('n', strtotime($f))];
$ultimoSabado = date('Y-m-d', strtotime($hoy . ' -' . ((((int) date('w', strtotime($hoy))) + 1) % 7) . ' days'));
$atajos = [['Hoy', $hoy]];
if ($ultimoSabado !== $hoy) {
    $atajos[] = ['Último sábado', $ultimoSabado];
}
$atajos[] = ['Sábado anterior', date('Y-m-d', strtotime($ultimoSabado . ' -7 days'))];
$urlFecha = fn(string $f) => 'asistencia.php?' . ($grupo_id ? 'grupo=' . $grupo_id . '&' : '') . 'fecha=' . urlencode($f);

$page_title = 'Tomar asistencia';
require __DIR__ . '/includes/header.php';
?>

<?php if ($grupo_id && $estudiantes): $ts = strtotime($fecha); ?>
<div class="hero-act">
    <div class="act-date"><?= $mesesHero[(int) date('n', $ts)] ?><strong><?= date('j', $ts) ?></strong></div>
    <div class="hero-main">
        <h1 class="hero-title"><?= e($grupoActual['nombre']) ?></h1>
        <div class="hero-pills">
            <span class="pill"><?= e(fecha_larga($ts)) ?></span>
            <span class="pill"><?= count($estudiantes) ?> <?= count($estudiantes) === 1 ? 'catequizando' : 'catequizandos' ?></span>
            <?php if ($sesion): ?>
                <span class="pill pill-ok">Sesión registrada</span>
            <?php else: ?>
                <span class="pill pill-gold">Sesión nueva</span>
            <?php endif; ?>
            <?php if ($fecha > $hoy): ?><span class="pill pill-gold">Fecha futura</span><?php endif; ?>
        </div>
    </div>
    <div class="hero-tools no-print">
        <?php if ($sesion): ?><a class="btn" href="reportes_sesion.php?id=<?= (int) $sesion['id'] ?>">Ver detalle</a><?php endif; ?>
        <a class="btn" href="asistencia.php?fecha=<?= e(urlencode($fecha)) ?>">Cambiar grupo</a>
    </div>
</div>
<?php else: ?>
<div class="dash-hero">
    <div>
        <h1>Tomar asistencia</h1>
        <p class="muted dash-date">Elige la fecha de la sesión y el grupo.</p>
    </div>
</div>
<?php endif; ?>

<form class="card ctx-bar no-print" method="get" action="asistencia.php" id="form-fecha">
    <?php if ($grupo_id): ?><input type="hidden" name="grupo" value="<?= $grupo_id ?>"><?php endif; ?>
    <span class="ctx-label" id="lbl-fecha">Fecha de la sesión</span>
    <div class="ctx-chips" role="group" aria-labelledby="lbl-fecha">
        <?php foreach ($atajos as [$etq, $f]): ?>
            <a class="date-chip<?= $f === $fecha ? ' is-on' : '' ?>" href="<?= e($urlFecha($f)) ?>" <?= $f === $fecha ? 'aria-current="date"' : '' ?>>
                <?= e($etq) ?><small><?= e($corta($f)) ?></small>
            </a>
        <?php endforeach; ?>
    </div>
    <label class="ctx-date">Otra fecha
        <input type="date" name="fecha" value="<?= e($fecha) ?>" required>
    </label>
    <noscript><button class="btn" type="submit">Ver</button></noscript>
</form>

<?php if (!$grupos): ?>
    <div class="card"><div class="empty-state">
        <span class="empty-icon"><?= nav_icon('book') ?></span>
        <strong>Todavía no hay grupos</strong>
        <a class="btn btn-small btn-primary" href="grupos.php">Crear un grupo</a>
    </div></div>

<?php elseif (!$grupo_id): ?>
    <?php
    $stmt = $pdo->prepare('
        SELECT g.id, g.nombre, g.descripcion,
               (SELECT COUNT(*) FROM estudiantes e WHERE e.grupo_id = g.id) AS n_est,
               (SELECT MAX(s.fecha) FROM sesiones s WHERE s.grupo_id = g.id) AS ultima,
               EXISTS(SELECT 1 FROM sesiones s WHERE s.grupo_id = g.id AND s.fecha = ?) AS tiene_sesion
        FROM grupos g ORDER BY g.nombre
    ');
    $stmt->execute([$fecha]);
    $tarjetas = $stmt->fetchAll();
    ?>
    <h2 class="section-h">¿Qué grupo va a pasar asistencia el <?= e($corta($fecha)) ?>?</h2>
    <div class="group-grid">
    <?php foreach ($tarjetas as $g): ?>
        <a class="group-card" href="<?= e('asistencia.php?grupo=' . (int) $g['id'] . '&fecha=' . urlencode($fecha)) ?>">
            <span class="group-ico"><?= nav_icon('book') ?></span>
            <span class="group-body">
                <strong class="group-name"><?= e($g['nombre']) ?></strong>
                <span class="muted"><?= (int) $g['n_est'] ?> <?= (int) $g['n_est'] === 1 ? 'catequizando' : 'catequizandos' ?>
                    · <?= $g['ultima'] ? 'última sesión ' . e($corta($g['ultima'])) : 'sin sesiones todavía' ?></span>
                <span class="group-state <?= $g['tiene_sesion'] ? 'is-done' : 'is-todo' ?>">
                    <?= $g['tiene_sesion'] ? 'Ya registrada ese día' : 'Falta tomar asistencia' ?>
                </span>
            </span>
            <span class="group-go" aria-hidden="true"><?= nav_icon('arrow') ?></span>
        </a>
    <?php endforeach; ?>
    </div>

<?php elseif (!$estudiantes): ?>
    <div class="card"><div class="empty-state">
        <span class="empty-icon"><?= nav_icon('users') ?></span>
        <strong><?= e($grupoActual['nombre']) ?> no tiene catequizandos</strong>
        <a class="btn btn-small btn-primary" href="estudiantes.php">Agregar catequizandos</a>
    </div></div>

<?php else: ?>
<form method="post" action="asistencia.php" id="form-asistencia" <?= $bloqueada ? 'class="is-locked" data-lock="1"' : '' ?>>
    <input type="hidden" name="accion" value="guardar">
    <input type="hidden" name="grupo_id" value="<?= (int) $grupo_id ?>">
    <input type="hidden" name="fecha" value="<?= e($fecha) ?>">

    <?php if ($bloqueada): ?>
    <div class="lock-banner no-print">
        <span class="lock-ico"><?= nav_icon('lock') ?></span>
        <div class="lock-view">
            <strong>Asistencia ya registrada</strong>
            <p>Está protegida para que no se cambie por accidente. Pulsa «Editar asistencia» si necesitas corregirla.<?= $sinMarcarInicial ? ' Hay ' . $sinMarcarInicial . ' sin marcar.' : '' ?></p>
        </div>
        <div class="lock-edit">
            <strong>Editando una asistencia guardada</strong>
            <p>Los cambios se aplican solo cuando pulses «Guardar asistencia». Los puntos y los reportes se recalculan.</p>
        </div>
    </div>
    <?php endif; ?>

    <div class="card progress-card">
        <div class="progress-top">
            <strong><span id="pts-total">0</span> <span class="pts-de">de <span id="pts-max"><?= count($estudiantes) ?></span> puntos</span></strong>
            <span class="muted" id="pts-pct">0%</span>
            <span class="muted" style="margin-left:auto" id="pts-marcados"></span>
        </div>
        <div class="progress-bar" aria-hidden="true"><div class="progress-fill" id="progress-fill"></div></div>
        <ul class="count-pills" aria-label="Resumen por tipo de asistencia">
            <?php foreach ($opcionesAsistencia as $op): ?>
                <li class="count-pill cp-<?= $op['clase'] ?>"><i aria-hidden="true"></i><?= e($op['etiqueta']) ?> <b id="cnt-<?= $op['valor'] ?>">0</b></li>
            <?php endforeach; ?>
        </ul>
        <div class="bulk no-print">
            <button class="btn btn-small" type="button" data-bulk="completo" data-lock-disable <?= $bloqueada ? 'disabled' : '' ?>><span aria-hidden="true">✓</span> Todos Misa y Catequesis</button>
            <button class="btn btn-small" type="button" data-bulk="ausente" data-lock-disable <?= $bloqueada ? 'disabled' : '' ?>><span aria-hidden="true">✕</span> Todos ausentes</button>
            <button class="btn btn-small btn-muted" type="button" data-bulk="limpiar" data-lock-disable <?= $bloqueada ? 'disabled' : '' ?>>Limpiar</button>
            <button class="btn btn-small" type="button" id="btn-deshacer" disabled><?= nav_icon('undo') ?> Deshacer</button>
            <label class="search-box">
                <?= nav_icon('search') ?>
                <input type="search" id="buscar-asistencia" aria-label="Buscar catequizando" placeholder="Buscar catequizando" autocomplete="off">
            </label>
        </div>
    </div>

    <div class="card tema-card">
        <label class="full">Tema de la sesión (opcional)
            <input type="text" name="tema" maxlength="200" value="<?= e($sesion['tema'] ?? '') ?>" placeholder="Ej: Los sacramentos" data-lock-readonly <?= $bloqueada ? 'readonly' : '' ?>>
        </label>
    </div>

    <div class="card" style="padding-top:8px;padding-bottom:8px">
        <ul class="ar-list">
        <?php foreach ($estudiantes as $es): ?>
            <?php $orig = $asistenciasPrevias[$es['id']] ?? ''; $nom = $es['nombres'] . ' ' . $es['apellidos']; ?>
            <li class="ar-row cat" data-orig="<?= e($orig) ?>" data-nombre="<?= e(mb_strtolower($nom)) ?>">
                <span class="avatar"><?= e(iniciales($es['nombres'], $es['apellidos'])) ?></span>
                <span class="ar-name"><?= e($nom) ?><i class="dot-changed" aria-hidden="true"></i><span class="tag-sin" hidden>Sin marcar</span></span>
                <input type="hidden" name="categoria[<?= (int) $es['id'] ?>]" value="">
                <div class="opts opts-4" role="radiogroup" aria-label="Asistencia de <?= e($nom) ?>">
                <?php foreach ($opcionesAsistencia as $op): ?>
                    <label class="opt opt-<?= $op['clase'] ?>">
                        <input type="radio" name="categoria[<?= (int) $es['id'] ?>]" value="<?= $op['valor'] ?>" data-puntos="<?= $op['puntos'] ?>" data-lock-disable <?= $orig === $op['valor'] ? 'checked' : '' ?> <?= $bloqueada ? 'disabled' : '' ?>>
                        <span class="opt-face">
                            <span class="opt-ico"><?= nav_icon($op['icono']) ?></span>
                            <span class="opt-txt"><?= e($op['etiqueta']) ?><em><?= e($op['pts']) ?></em></span>
                        </span>
                    </label>
                <?php endforeach; ?>
                </div>
            </li>
        <?php endforeach; ?>
        </ul>
        <div class="empty-state" id="sin-resultados" role="status" hidden>
            <span class="empty-icon"><?= nav_icon('search') ?></span><strong>Nadie coincide con la búsqueda</strong>
        </div>
    </div>

    <p class="sr-only" id="live" role="status" aria-live="polite"></p>
    <div class="save-bar solo no-print">
        <div class="save-info">
            <span class="mini"><i id="mini-fill"></i></span>
            <span class="save-status" id="save-status">Sin marcar</span>
        </div>
        <div class="save-actions">
            <?php if ($bloqueada): ?>
                <button class="btn btn-primary" type="button" id="btn-editar"><?= nav_icon('edit') ?> Editar asistencia</button>
                <button class="btn btn-muted" type="button" id="btn-cancelar-edicion">Cancelar edición</button>
            <?php endif; ?>
            <button class="btn btn-primary" type="submit" id="btn-guardar" disabled>Guardar asistencia</button>
        </div>
    </div>
</form>

<?php if ($bloqueada): ?>
<dialog class="dlg" id="dlg-editar" role="alertdialog" aria-labelledby="dlg-editar-t" aria-describedby="dlg-editar-d" data-fallback="¿Quieres editar la asistencia ya guardada?">
    <form method="dialog">
        <div class="dlg-body">
            <span class="dlg-ico"><?= nav_icon('edit') ?></span>
            <h2 id="dlg-editar-t">¿Quieres editar esta asistencia?</h2>
            <p id="dlg-editar-d" class="muted">La asistencia de <strong><?= e($grupoActual['nombre']) ?></strong> del <?= e(fecha_larga(strtotime($fecha))) ?> ya está guardada. Si continúas podrás cambiarla; nada se modifica hasta que pulses «Guardar asistencia».</p>
        </div>
        <div class="dlg-foot">
            <button class="btn" value="cancelar" autofocus>No, dejarla como está</button>
            <button class="btn btn-primary" value="ok">Sí, quiero editar</button>
        </div>
    </form>
</dialog>
<script src="<?= asset('assets/js/bloqueo.js') ?>"></script>
<?php endif; ?>
<script src="<?= asset('assets/js/asistencia.js') ?>"></script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
