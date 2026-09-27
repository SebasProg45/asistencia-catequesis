<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/functions.php';

$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT act.*, g.nombre AS grupo_nombre FROM actividades act JOIN grupos g ON g.id = act.grupo_id WHERE act.id = ?');
$stmt->execute([$id]);
$actividad = $stmt->fetch();

if (!$actividad) {
    set_flash('error', 'Actividad no encontrada.');
    redirect('actividades.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {
    $asistenciaPost = is_array($_POST['asistio'] ?? null) ? $_POST['asistio'] : [];

    $upsert = $pdo->prepare('
        INSERT INTO actividad_asistencias (actividad_id, estudiante_id, asistio) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE asistio = VALUES(asistio)
    ');

    $borrar = $pdo->prepare('DELETE FROM actividad_asistencias WHERE actividad_id = ? AND estudiante_id = ?');

    // Solo se aceptan catequizandos del grupo de la actividad.
    $stmtIds = $pdo->prepare('SELECT id FROM estudiantes WHERE grupo_id = ?');
    $stmtIds->execute([$actividad['grupo_id']]);
    $idsGrupo = array_flip(array_map('intval', $stmtIds->fetchAll(PDO::FETCH_COLUMN)));

    try {
        $guardados = 0;
        $asistieron = 0;
        $sinMarca = 0;
        foreach ($asistenciaPost as $estudiante_id => $valor) {
            if (!isset($idsGrupo[(int) $estudiante_id])) {
                continue;
            }
            if ($valor === '') {
                // Sin marca: si tenía un registro anterior, se elimina (así "Limpiar" se puede guardar).
                $borrar->execute([$id, (int) $estudiante_id]);
                $sinMarca += $borrar->rowCount();
                continue;
            }
            if (!in_array($valor, ['1', '0'], true)) {
                continue;
            }
            $upsert->execute([$id, (int) $estudiante_id, $valor]);
            $guardados++;
            $asistieron += (int) $valor;
        }
        set_flash('success', "Asistencia guardada: $asistieron de $guardados marcados asistieron."
            . ($sinMarca > 0 ? " Se quitó la marca a $sinMarca." : ''));
    } catch (PDOException $e) {
        set_flash('error', 'No se pudo guardar la asistencia. Intenta de nuevo.');
    }

    redirect('actividad.php?id=' . $id);
}

$stmt = $pdo->prepare('SELECT * FROM estudiantes WHERE grupo_id = ? ORDER BY nombres, apellidos');
$stmt->execute([$actividad['grupo_id']]);
$estudiantes = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT estudiante_id, asistio FROM actividad_asistencias WHERE actividad_id = ?');
$stmt->execute([$id]);
$asistenciasPrevias = [];
foreach ($stmt->fetchAll() as $row) {
    $asistenciasPrevias[$row['estudiante_id']] = (string) $row['asistio'];
}

// Con asistencia ya guardada la pantalla se abre protegida: hay que confirmar antes de editar.
$bloqueada = (bool) $asistenciasPrevias;
$sinMarcarInicial = count(array_filter($estudiantes, fn($es) => !isset($asistenciasPrevias[$es['id']])));

$ts = strtotime($actividad['fecha']);
$meses = ['', 'ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];

$page_title = 'Asistencia — ' . $actividad['nombre'];
require __DIR__ . '/includes/header.php';
?>
<div class="hero-act">
    <div class="act-date"><?= $meses[(int) date('n', $ts)] ?><strong><?= date('j', $ts) ?></strong></div>
    <div class="hero-main">
        <h1 class="hero-title"><?= e($actividad['nombre']) ?></h1>
        <div class="hero-pills">
            <span class="pill"><?= e($actividad['grupo_nombre']) ?></span>
            <span class="pill"><?= e(fecha_larga($ts)) ?></span>
            <span class="pill"><?= count($estudiantes) ?> <?= count($estudiantes) === 1 ? 'catequizando' : 'catequizandos' ?></span>
            <span class="pill pill-gold">Solo registro de participación</span>
        </div>
        <?php if ($actividad['descripcion']): ?><p class="hero-desc"><?= e($actividad['descripcion']) ?></p><?php endif; ?>
    </div>
    <div class="hero-tools no-print">
        <button class="btn" type="button" onclick="window.print()">Imprimir</button>
        <a class="btn" href="actividades.php">← Actividades</a>
    </div>
</div>

<?php if (!$estudiantes): ?>
    <div class="card">
        <div class="empty-state">
            <span class="empty-icon"><?= nav_icon('users') ?></span>
            <strong>Este grupo no tiene catequizandos</strong>
            <a class="btn btn-small btn-primary" href="estudiantes.php">Agregar catequizandos</a>
        </div>
    </div>
<?php else: ?>
<form method="post" action="actividad.php?id=<?= $id ?>" id="form-actividad-asistencia" <?= $bloqueada ? 'class="is-locked" data-lock="1"' : '' ?>>
    <input type="hidden" name="accion" value="guardar">

    <?php if ($bloqueada): ?>
    <div class="lock-banner no-print">
        <span class="lock-ico"><?= nav_icon('lock') ?></span>
        <div class="lock-view">
            <strong>Asistencia ya registrada</strong>
            <p>Está protegida para que no se cambie por accidente. Pulsa «Editar asistencia» si necesitas corregirla.<?= $sinMarcarInicial ? ' Hay ' . $sinMarcarInicial . ' sin marcar.' : '' ?></p>
        </div>
        <div class="lock-edit">
            <strong>Editando una asistencia guardada</strong>
            <p>Los cambios se aplican solo cuando pulses «Guardar asistencia».</p>
        </div>
    </div>
    <?php endif; ?>

    <div class="card progress-card">
        <div class="progress-top">
            <strong><span id="asistio-si">0</span> de <span id="asistio-n"><?= count($estudiantes) ?></span> asistieron</strong>
            <span class="muted" id="asistio-pct">0%</span>
            <span class="muted" style="margin-left:auto" id="asistio-marcados"></span>
        </div>
        <div class="progress-bar" aria-hidden="true"><div class="progress-fill" id="progress-fill"></div></div>
        <div class="bulk no-print">
            <button class="btn btn-small" type="button" data-bulk="si" data-lock-disable <?= $bloqueada ? 'disabled' : '' ?>><span aria-hidden="true">✓</span> Todos asistieron</button>
            <button class="btn btn-small" type="button" data-bulk="no" data-lock-disable <?= $bloqueada ? 'disabled' : '' ?>><span aria-hidden="true">✕</span> Ninguno</button>
            <button class="btn btn-small" type="button" data-bulk="invertir" data-lock-disable <?= $bloqueada ? 'disabled' : '' ?>><span aria-hidden="true">⇄</span> Invertir</button>
            <button class="btn btn-small btn-muted" type="button" data-bulk="limpiar" data-lock-disable <?= $bloqueada ? 'disabled' : '' ?>>Limpiar</button>
            <button class="btn btn-small" type="button" id="btn-deshacer" disabled><?= nav_icon('undo') ?> Deshacer</button>
            <label class="search-box">
                <?= nav_icon('search') ?>
                <input type="search" id="buscar-asistencia" aria-label="Buscar catequizando" placeholder="Buscar catequizando" autocomplete="off">
            </label>
        </div>
    </div>

    <div class="card" style="padding-top:8px;padding-bottom:8px">
        <ul class="ar-list">
        <?php foreach ($estudiantes as $es): ?>
            <?php $orig = $asistenciasPrevias[$es['id']] ?? ''; $nom = $es['nombres'] . ' ' . $es['apellidos']; ?>
            <li class="ar-row" data-orig="<?= e($orig) ?>" data-nombre="<?= e(mb_strtolower($nom)) ?>">
                <span class="avatar"><?= e(iniciales($es['nombres'], $es['apellidos'])) ?></span>
                <span class="ar-name"><?= e($nom) ?><i class="dot-changed" aria-hidden="true"></i><span class="tag-sin" hidden>Sin marcar</span></span>
                <input type="hidden" name="asistio[<?= (int) $es['id'] ?>]" value="">
                <div class="opts opts-2" role="radiogroup" aria-label="Asistencia de <?= e($nom) ?>">
                    <label class="opt opt-yes">
                        <input type="radio" name="asistio[<?= (int) $es['id'] ?>]" value="1" data-lock-disable <?= $orig === '1' ? 'checked' : '' ?> <?= $bloqueada ? 'disabled' : '' ?>>
                        <span class="opt-face"><span class="opt-ico"><?= nav_icon('tick') ?></span><span class="opt-txt">Asistió</span></span>
                    </label>
                    <label class="opt opt-no">
                        <input type="radio" name="asistio[<?= (int) $es['id'] ?>]" value="0" data-lock-disable <?= $orig === '0' ? 'checked' : '' ?> <?= $bloqueada ? 'disabled' : '' ?>>
                        <span class="opt-face"><span class="opt-ico"><?= nav_icon('x') ?></span><span class="opt-txt">No asistió</span></span>
                    </label>
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
            <p id="dlg-editar-d" class="muted">La asistencia de <strong><?= e($actividad['nombre']) ?></strong> ya está guardada. Si continúas podrás cambiarla; nada se modifica hasta que pulses «Guardar asistencia».</p>
        </div>
        <div class="dlg-foot">
            <button class="btn" value="cancelar" autofocus>No, dejarla como está</button>
            <button class="btn btn-primary" value="ok">Sí, quiero editar</button>
        </div>
    </form>
</dialog>
<script src="<?= asset('assets/js/bloqueo.js') ?>"></script>
<?php endif; ?>
<script src="<?= asset('assets/js/actividad.js') ?>"></script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
