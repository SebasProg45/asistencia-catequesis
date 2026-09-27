<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/functions.php';

$grupos = $pdo->query('SELECT * FROM grupos ORDER BY nombre')->fetchAll();
$hoy = date('Y-m-d');

// El estado de la vista (pestaña, búsqueda, grupo) vive en la URL; se conserva tras guardar.
function volver_seguro(): string
{
    $v = $_POST['volver_a'] ?? '';
    return is_string($v) && preg_match('/^\?[A-Za-z0-9_=&%\-.+]*$/', $v) ? $v : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $destino = 'actividades.php' . volver_seguro();

    if ($accion === 'crear' || $accion === 'editar') {
        $nombre = campo($_POST, 'nombre');
        $fecha = campo($_POST, 'fecha');
        $grupo_id = (int) ($_POST['grupo_id'] ?? 0);
        $descripcion = campo($_POST, 'descripcion') ?: null;
        $fechaValida = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) && strtotime($fecha) !== false;

        if ($nombre === '' || !$fechaValida || $grupo_id <= 0) {
            set_flash('error', 'Nombre, fecha y grupo son obligatorios.');
        } else {
            try {
                if ($accion === 'crear') {
                    $stmt = $pdo->prepare('INSERT INTO actividades (grupo_id, nombre, fecha, descripcion) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$grupo_id, $nombre, $fecha, $descripcion]);
                    $nuevoId = (int) $pdo->lastInsertId();
                    set_flash('success', 'Actividad creada.');
                    if (($_POST['despues'] ?? '') === 'asistencia') {
                        redirect('actividad.php?id=' . $nuevoId);
                    }
                } else {
                    $id = (int) ($_POST['id'] ?? 0);
                    $stmt = $pdo->prepare('UPDATE actividades SET grupo_id=?, nombre=?, fecha=?, descripcion=? WHERE id=?');
                    $stmt->execute([$grupo_id, $nombre, $fecha, $descripcion, $id]);
                    set_flash('success', 'Actividad actualizada.');
                }
            } catch (PDOException $e) {
                set_flash('error', 'No se pudo guardar la actividad. Verifica los datos e intenta de nuevo.');
            }
        }
    } elseif ($accion === 'eliminar') {
        $id = (int) ($_POST['id'] ?? 0);
        try {
            // Su asistencia se elimina en cascada por la clave foránea.
            $stmt = $pdo->prepare('DELETE FROM actividades WHERE id = ?');
            $stmt->execute([$id]);
            set_flash('success', 'Actividad eliminada.');
        } catch (PDOException $e) {
            set_flash('error', 'No se pudo eliminar la actividad. Intenta de nuevo.');
        }
    }

    redirect($destino);
}

// ---- Actividades con su participación y quiénes asistieron ----
$pdo->exec('SET SESSION group_concat_max_len = 100000');
$filas = $pdo->query("
    SELECT act.id, act.nombre, act.fecha, act.descripcion, act.grupo_id, g.nombre AS grupo_nombre,
        (SELECT COUNT(*) FROM estudiantes e WHERE e.grupo_id = act.grupo_id) AS total_grupo,
        COUNT(aa.id) AS registrados,
        COALESCE(SUM(aa.asistio), 0) AS asistieron,
        GROUP_CONCAT(CASE WHEN aa.asistio = 1 THEN CONCAT(es.nombres, ' ', es.apellidos) END ORDER BY es.nombres, es.apellidos SEPARATOR '||') AS lista_si,
        GROUP_CONCAT(CASE WHEN aa.asistio = 0 THEN CONCAT(es.nombres, ' ', es.apellidos) END ORDER BY es.nombres, es.apellidos SEPARATOR '||') AS lista_no
    FROM actividades act
    JOIN grupos g ON g.id = act.grupo_id
    LEFT JOIN actividad_asistencias aa ON aa.actividad_id = act.id
    LEFT JOIN estudiantes es ON es.id = aa.estudiante_id
    GROUP BY act.id, g.nombre
    ORDER BY act.fecha DESC, act.id DESC
")->fetchAll();

$proximas = [];
$pasadas = [];
$sumAsistieron = 0;
$sumRegistrados = 0;
foreach ($filas as $f) {
    $f['si'] = $f['lista_si'] !== null && $f['lista_si'] !== '' ? explode('||', $f['lista_si']) : [];
    $f['no'] = $f['lista_no'] !== null && $f['lista_no'] !== '' ? explode('||', $f['lista_no']) : [];
    $f['pct'] = $f['registrados'] > 0 ? (int) round(($f['asistieron'] / $f['registrados']) * 100) : null;

    if ($f['fecha'] > $hoy) {
        $f['estado'] = 'proxima';
        $proximas[] = $f;
    } else {
        $f['estado'] = $f['fecha'] === $hoy ? 'hoy' : ($f['registrados'] > 0 ? 'realizada' : 'pendiente');
        if ($f['estado'] === 'hoy') {
            $proximas[] = $f;
        } else {
            $pasadas[] = $f;
        }
        if ($f['registrados'] > 0) {
            $sumAsistieron += (int) $f['asistieron'];
            $sumRegistrados += (int) $f['registrados'];
        }
    }
}
usort($proximas, fn($a, $b) => strcmp($a['fecha'], $b['fecha']));   // la más cercana primero
$participacionMedia = $sumRegistrados > 0 ? (int) round(($sumAsistieron / $sumRegistrados) * 100) : null;
$pendientes = count(array_filter($pasadas, fn($a) => $a['estado'] === 'pendiente'));

// ---- Participación por catequizando (su propio historial, sin importar el grupo actual) ----
$rows = $pdo->query("
    SELECT es.id, es.nombres, es.apellidos, es.grupo_id, g.nombre AS grupo_nombre,
           act.nombre AS act_nombre, act.fecha AS act_fecha, aa.asistio
    FROM estudiantes es
    JOIN grupos g ON g.id = es.grupo_id
    LEFT JOIN actividad_asistencias aa ON aa.estudiante_id = es.id
    LEFT JOIN actividades act ON act.id = aa.actividad_id
    ORDER BY es.nombres, es.apellidos, act.fecha
")->fetchAll();
$participacion = [];
foreach ($rows as $r) {
    $id = $r['id'];
    if (!isset($participacion[$id])) {
        $participacion[$id] = ['id' => $id, 'nombres' => $r['nombres'], 'apellidos' => $r['apellidos'],
            'grupo_id' => $r['grupo_id'], 'grupo_nombre' => $r['grupo_nombre'], 'acts' => [], 'si' => 0];
    }
    if ($r['act_nombre'] !== null) {
        $participacion[$id]['acts'][] = ['nombre' => $r['act_nombre'], 'fecha' => $r['act_fecha'], 'asistio' => (int) $r['asistio']];
        $participacion[$id]['si'] += (int) $r['asistio'];
    }
}

function nivel_ring(?int $pct): string
{
    return $pct === null ? 'none' : ($pct >= 75 ? 'ok' : ($pct >= 40 ? 'mid' : 'low'));
}
function iniciales_nombre(string $completo): string
{
    $p = preg_split('/\s+/', trim($completo));
    return mb_strtoupper(mb_substr($p[0], 0, 1) . (count($p) > 1 ? mb_substr(end($p), 0, 1) : ''));
}
$meses = ['', 'ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];

function tarjeta_actividad(array $a, array $meses): void
{
    $ts = strtotime($a['fecha']);
    $q = mb_strtolower($a['nombre'] . ' ' . $a['grupo_nombre'] . ' ' . ($a['descripcion'] ?? ''));
    $ring = nivel_ring($a['pct']);
    $etiquetas = ['proxima' => ['badge-info', 'Próxima'], 'hoy' => ['badge-hoy', 'Hoy'], 'realizada' => ['badge-ok', 'Realizada'], 'pendiente' => ['badge-warn', 'Falta tomar asistencia']];
    [$claseBadge, $textoBadge] = $etiquetas[$a['estado']];
    $tab = in_array($a['estado'], ['proxima', 'hoy'], true) ? 'proximas' : 'pasadas';
    ?>
    <article class="act-card act-<?= e($a['estado']) ?>" data-tab="<?= $tab ?>" data-grupo="<?= (int) $a['grupo_id'] ?>" data-q="<?= e($q) ?>">
        <div class="act-date"><?= $meses[(int) date('n', $ts)] ?><strong><?= date('j', $ts) ?></strong></div>
        <div class="act-body">
            <h2 class="act-title"><a class="act-open" href="actividad.php?id=<?= (int) $a['id'] ?>"><?= e($a['nombre']) ?></a></h2>
            <p class="muted act-sub"><?= e($a['grupo_nombre']) ?><?= $a['descripcion'] ? ' · ' . e($a['descripcion']) : '' ?></p>
            <div class="act-meta">
                <span class="badge <?= $claseBadge ?>"><?= $textoBadge ?></span>
                <?php if ($a['si']): ?>
                    <span class="avatar-stack" aria-hidden="true">
                        <?php foreach (array_slice($a['si'], 0, 4) as $n): ?><span class="avatar-sm"><?= e(iniciales_nombre($n)) ?></span><?php endforeach; ?>
                        <?php if (count($a['si']) > 4): ?><span class="avatar-sm avatar-more">+<?= count($a['si']) - 4 ?></span><?php endif; ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="act-side">
            <?php if ($a['pct'] !== null): ?>
                <div class="ring ring-<?= $ring ?>" style="--p:<?= $a['pct'] ?>" role="img" aria-label="<?= (int) $a['asistieron'] ?> de <?= (int) $a['registrados'] ?> asistieron, <?= $a['pct'] ?>%">
                    <span><b><?= $a['pct'] ?>%</b></span>
                </div>
                <span class="ring-cap"><?= (int) $a['asistieron'] ?> de <?= (int) $a['registrados'] ?></span>
            <?php elseif ($a['estado'] === 'proxima' || $a['estado'] === 'hoy'): ?>
                <span><?= (int) $a['total_grupo'] ?> <?= (int) $a['total_grupo'] === 1 ? 'catequizando' : 'catequizandos' ?></span>
            <?php else: ?>
                <span>Sin datos</span>
            <?php endif; ?>
        </div>
        <div class="act-actions">
            <button type="button" class="icon-btn" title="Editar" aria-label="Editar <?= e($a['nombre']) ?>"
                data-editar data-id="<?= (int) $a['id'] ?>" data-nombre="<?= e($a['nombre']) ?>" data-fecha="<?= e($a['fecha']) ?>"
                data-grupo="<?= (int) $a['grupo_id'] ?>" data-desc="<?= e($a['descripcion'] ?? '') ?>"><?= nav_icon('edit') ?></button>
            <button type="button" class="icon-btn danger" title="Eliminar" aria-label="Eliminar <?= e($a['nombre']) ?>"
                data-eliminar data-id="<?= (int) $a['id'] ?>" data-nombre="<?= e($a['nombre']) ?>"
                data-fecha="<?= e(date('d/m/Y', $ts)) ?>" data-registrados="<?= (int) $a['registrados'] ?>"><?= nav_icon('trash') ?></button>
        </div>
        <?php if ($a['si'] || $a['no']): ?>
        <details class="act-details">
            <summary>Ver quién asistió</summary>
            <div class="who">
                <div><h3>Asistieron (<?= count($a['si']) ?>)</h3><ul><?php foreach ($a['si'] as $n): ?><li><?= e($n) ?></li><?php endforeach; ?></ul></div>
                <div class="who-no"><h3>No asistieron (<?= count($a['no']) ?>)</h3><ul><?php foreach ($a['no'] as $n): ?><li><?= e($n) ?></li><?php endforeach; ?></ul></div>
            </div>
        </details>
        <?php endif; ?>
    </article>
    <?php
}

$tabInicial = $proximas ? 'proximas' : 'pasadas';
$page_title = 'Actividades';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <h1 style="margin-bottom:2px">Actividades</h1>
        <p class="muted" style="margin:0">Retiros, procesiones y jornadas fuera de los sábados. Solo registran participación: no suman ni restan puntos.</p>
    </div>
    <button class="btn btn-primary" type="button" data-nueva><?= nav_icon('plus') ?> Nueva actividad</button>
</div>

<?php if (!$grupos): ?>
    <div class="flash error">Primero crea un <a href="grupos.php">grupo</a>.</div>
<?php endif; ?>

<div class="stats-grid stats-3">
    <div class="stat-card">
        <span class="stat-icon"><?= nav_icon('calendar') ?></span>
        <span class="stat-body"><span class="stat-value"><?= count($proximas) ?></span><span class="stat-label"><?= count($proximas) === 1 ? 'Próxima' : 'Próximas' ?></span></span>
    </div>
    <div class="stat-card">
        <span class="stat-icon"><?= nav_icon('check') ?></span>
        <span class="stat-body"><span class="stat-value"><?= count($pasadas) - $pendientes ?></span><span class="stat-label"><?= (count($pasadas) - $pendientes) === 1 ? 'Realizada' : 'Realizadas' ?></span></span>
    </div>
    <div class="stat-card stat-<?= nivel_ring($participacionMedia) ?>">
        <span class="stat-icon"><?= nav_icon('trend') ?></span>
        <span class="stat-body"><span class="stat-value"><?= $participacionMedia !== null ? $participacionMedia . '%' : '—' ?></span><span class="stat-label">Participación media</span></span>
    </div>
</div>

<p class="sr-only" id="live-act" role="status" aria-live="polite"></p>
<?php if (!$filas): ?>
    <div class="card">
        <div class="empty-state">
            <span class="empty-icon"><?= nav_icon('calendar') ?></span>
            <strong>Aún no hay actividades</strong>
            <span class="muted">Crea la primera para empezar a registrar la participación.</span>
            <button class="btn btn-primary" type="button" data-nueva><?= nav_icon('plus') ?> Crear la primera actividad</button>
        </div>
    </div>
<?php else: ?>
<div class="act-toolbar">
    <div class="tabs" role="tablist" aria-label="Vista">
        <button class="tab" type="button" role="tab" id="tab-proximas" aria-controls="vista-proximas" data-tab="proximas" aria-selected="false" tabindex="-1">Próximas <span class="count"><?= count($proximas) ?></span></button>
        <button class="tab" type="button" role="tab" id="tab-pasadas" aria-controls="vista-pasadas" data-tab="pasadas" aria-selected="false" tabindex="-1">Pasadas <span class="count"><?= count($pasadas) ?></span></button>
        <button class="tab" type="button" role="tab" id="tab-personas" aria-controls="vista-personas" data-tab="personas" aria-selected="false" tabindex="-1">Por catequizando <span class="count"><?= count($participacion) ?></span></button>
    </div>
    <div class="act-filters">
        <label class="search-box">
            <?= nav_icon('search') ?>
            <input type="search" id="buscar" aria-label="Buscar actividad o catequizando" placeholder="Buscar actividad o catequizando" autocomplete="off">
        </label>
        <?php if (count($grupos) > 1): ?>
        <select id="filtro-grupo" aria-label="Filtrar por grupo">
            <option value="">Todos los grupos</option>
            <?php foreach ($grupos as $g): ?><option value="<?= (int) $g['id'] ?>"><?= e($g['nombre']) ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
    </div>
</div>

<section data-vista="proximas" id="vista-proximas" role="tabpanel" aria-labelledby="tab-proximas" hidden>
    <div class="act-list">
        <?php foreach ($proximas as $a) { tarjeta_actividad($a, $meses); } ?>
    </div>
    <div class="card act-empty" data-vacio>
        <div class="empty-state">
            <span class="empty-icon"><?= nav_icon('calendar') ?></span>
            <strong>No hay actividades próximas</strong>
            <span class="muted">Programa la siguiente para que aparezca aquí.</span>
            <button class="btn btn-small btn-primary" type="button" data-nueva><?= nav_icon('plus') ?> Nueva actividad</button>
        </div>
    </div>
</section>

<section data-vista="pasadas" id="vista-pasadas" role="tabpanel" aria-labelledby="tab-pasadas" hidden>
    <?php if ($pendientes > 0): ?>
        <p class="muted" style="margin:0 0 12px"><strong><?= $pendientes ?></strong> <?= $pendientes === 1 ? 'actividad pasada todavía no tiene' : 'actividades pasadas todavía no tienen' ?> asistencia registrada.</p>
    <?php endif; ?>
    <div class="act-list">
        <?php foreach ($pasadas as $a) { tarjeta_actividad($a, $meses); } ?>
    </div>
    <div class="card act-empty" data-vacio>
        <div class="empty-state">
            <span class="empty-icon"><?= nav_icon('search') ?></span>
            <strong>Sin resultados</strong>
            <span class="muted">Prueba con otra búsqueda o cambia el grupo.</span>
        </div>
    </div>
</section>

<section data-vista="personas" id="vista-personas" role="tabpanel" aria-labelledby="tab-personas" hidden>
    <div class="card">
        <div class="part-tools">
            <label style="flex-direction:row;align-items:center;text-transform:none;letter-spacing:0">Ordenar por
                <select id="orden-part">
                    <option value="nombre">Nombre</option>
                    <option value="mas">Quien más participa</option>
                    <option value="menos">Quien menos participa</option>
                </select>
            </label>
        </div>
        <ul class="part-list" id="lista-part">
            <?php foreach ($participacion as $p): ?>
                <?php $tot = count($p['acts']); $pct = $tot > 0 ? (int) round(($p['si'] / $tot) * 100) : -1; ?>
                <li class="part-item" data-grupo="<?= (int) $p['grupo_id'] ?>" data-q="<?= e(mb_strtolower($p['nombres'] . ' ' . $p['apellidos'])) ?>"
                    data-nombre="<?= e(mb_strtolower($p['nombres'] . ' ' . $p['apellidos'])) ?>" data-pct="<?= $pct ?>" data-si="<?= (int) $p['si'] ?>">
                    <details>
                        <summary>
                            <span class="avatar"><?= e(iniciales($p['nombres'], $p['apellidos'])) ?></span>
                            <span class="part-name"><?= e($p['nombres']) ?> <?= e($p['apellidos']) ?><small><?= e($p['grupo_nombre']) ?></small></span>
                            <?= $tot > 0 ? barra_meta((float) $p['si'], (float) $tot) : '<span class="muted part-none">Sin actividades</span>' ?>
                            <span class="part-count"><?= $tot > 0 ? $p['si'] . ' de ' . $tot . ' · ' . $pct . '%' : '—' ?><?= ($tot >= 2 && $pct < 40) ? ' · Participa poco' : '' ?></span>
                        </summary>
                        <?php if ($tot > 0): ?>
                        <ul class="part-acts">
                            <?php foreach ($p['acts'] as $ac): ?>
                                <li class="<?= $ac['asistio'] ? '' : 'no' ?>"><?= e($ac['nombre']) ?><span class="muted"><?= date('d/m/Y', strtotime($ac['fecha'])) ?> · <?= $ac['asistio'] ? 'Asistió' : 'No asistió' ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </details>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="empty-state act-empty" data-vacio>
            <span class="empty-icon"><?= nav_icon('search') ?></span><strong>Sin resultados</strong>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Crear / editar -->
<dialog class="dlg" id="dlg-actividad" aria-labelledby="dlg-titulo">
    <form method="post" action="actividades.php" id="form-actividad" novalidate>
        <input type="hidden" name="accion" value="crear">
        <input type="hidden" name="id" value="">
        <input type="hidden" name="volver_a" value="">
        <div class="dlg-body">
            <h2 id="dlg-titulo">Nueva actividad</h2>
            <div>
                <label>Nombre <input type="text" name="nombre" maxlength="150" placeholder="Ej: Retiro de Adviento" autocomplete="off" aria-required="true" aria-describedby="err-nombre"></label>
                <span class="field-err" id="err-nombre" data-err="nombre"></span>
            </div>
            <div class="form-inline" style="align-items:flex-start">
                <div style="flex:1">
                    <label>Fecha <input type="date" name="fecha" aria-required="true" aria-describedby="err-fecha"></label>
                    <span class="field-err" id="err-fecha" data-err="fecha"></span>
                </div>
                <div style="flex:1">
                    <label>Grupo
                        <select name="grupo_id" aria-required="true" aria-describedby="err-grupo_id">
                            <option value="">-- Selecciona --</option>
                            <?php foreach ($grupos as $g): ?><option value="<?= (int) $g['id'] ?>"><?= e($g['nombre']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <span class="field-err" id="err-grupo_id" data-err="grupo_id"></span>
                </div>
            </div>
            <label>Descripción (opcional) <input type="text" name="descripcion" maxlength="255" placeholder="Lugar, hora, qué llevar…"></label>
        </div>
        <div class="dlg-foot">
            <button class="btn btn-muted" type="button" data-cerrar>Cancelar</button>
            <button class="btn" type="submit">Guardar</button>
            <button class="btn btn-primary" type="submit" name="despues" value="asistencia" data-solo-crear>Guardar y tomar asistencia</button>
        </div>
    </form>
</dialog>

<!-- Eliminar -->
<dialog class="dlg" id="dlg-eliminar" role="alertdialog" aria-labelledby="dlg-el-titulo" aria-describedby="el-texto">
    <form method="post" action="actividades.php" id="form-eliminar">
        <input type="hidden" name="accion" value="eliminar">
        <input type="hidden" name="id" value="">
        <input type="hidden" name="volver_a" value="">
        <div class="dlg-body">
            <h2 id="dlg-el-titulo">Eliminar actividad</h2>
            <p id="el-texto" style="margin:0"></p>
            <label class="confirm-box" id="el-confirma" hidden><input type="checkbox" id="el-check"> <span>Entiendo que se borra la asistencia registrada y no se puede deshacer.</span></label>
        </div>
        <div class="dlg-foot">
            <button class="btn btn-muted" type="button" data-cerrar autofocus>Cancelar</button>
            <button class="btn btn-danger" type="submit" id="el-boton" style="margin-left:0">Eliminar actividad</button>
        </div>
    </form>
</dialog>

<script>window.ACT_TAB_INICIAL = <?= json_encode($tabInicial) ?>;</script>
<script src="<?= asset('assets/js/actividades.js') ?>"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
