<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/functions.php';

$grupos = $pdo->query('SELECT * FROM grupos ORDER BY nombre')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear' || $accion === 'editar') {
        $nombre = trim($_POST['nombre'] ?? '');
        $fecha = $_POST['fecha'] ?? '';
        $grupo_id = (int) ($_POST['grupo_id'] ?? 0);
        $descripcion = trim($_POST['descripcion'] ?? '') ?: null;

        if ($nombre === '' || !$fecha || $grupo_id <= 0) {
            set_flash('error', 'Nombre, fecha y grupo son obligatorios.');
        } else {
            try {
                if ($accion === 'crear') {
                    $stmt = $pdo->prepare('INSERT INTO actividades (grupo_id, nombre, fecha, descripcion) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$grupo_id, $nombre, $fecha, $descripcion]);
                    set_flash('success', 'Actividad creada.');
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
    }

    redirect('actividades.php' . ($_POST['volver_a'] ?? ''));
}

$editando = null;
if (!empty($_GET['editar'])) {
    $stmt = $pdo->prepare('SELECT * FROM actividades WHERE id = ?');
    $stmt->execute([(int) $_GET['editar']]);
    $editando = $stmt->fetch() ?: null;
}

// --- Listado de actividades ---
$sortCol = $_GET['sort'] ?? 'fecha';
$sortDir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$sortColumns = [
    'nombre' => ['act.nombre'],
    'fecha' => ['act.fecha'],
    'grupo' => ['g.nombre'],
    'asistieron' => ['asistieron'],
];
if (!isset($sortColumns[$sortCol])) {
    $sortCol = 'fecha';
}
$dirSql = strtoupper($sortDir);
$orderSql = implode(', ', array_map(fn($c) => "$c $dirSql", $sortColumns[$sortCol]));

$actividades = $pdo->query("
    SELECT act.id, act.nombre, act.fecha, act.descripcion, g.nombre AS grupo_nombre,
        COUNT(aa.id) AS total_registrados,
        COALESCE(SUM(aa.asistio), 0) AS asistieron
    FROM actividades act
    JOIN grupos g ON g.id = act.grupo_id
    LEFT JOIN actividad_asistencias aa ON aa.actividad_id = act.id
    GROUP BY act.id, act.nombre, act.fecha, act.descripcion, g.nombre
    ORDER BY $orderSql
")->fetchAll();

// --- Resumen de participación por catequizando ---
$grupoResumen = isset($_GET['grupo']) && $_GET['grupo'] !== '' ? (int) $_GET['grupo'] : null;
$rsortCol = $_GET['rsort'] ?? 'nombre';
$rsortDir = ($_GET['rdir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$rsortColumns = [
    'nombre' => ['es.nombres', 'es.apellidos'],
    'grupo' => ['g.nombre'],
    'asistidas' => ['asistidas'],
    'total' => ['total_actividades'],
];
if (!isset($rsortColumns[$rsortCol])) {
    $rsortCol = 'nombre';
}
$rDirSql = strtoupper($rsortDir);
$rOrderParts = array_map(fn($c) => "$c $rDirSql", $rsortColumns[$rsortCol]);
$rOrderSql = implode(', ', $rOrderParts);

// Se une por estudiante_id (su propia participación histórica), no por es.grupo_id:
// si no, cambiar a un catequizando de grupo borraría en silencio su historial de actividades.
$stmt = $pdo->prepare("
    SELECT es.id, es.nombres, es.apellidos, g.nombre AS grupo_nombre,
        COUNT(aa.id) AS total_actividades,
        COALESCE(SUM(aa.asistio), 0) AS asistidas
    FROM estudiantes es
    JOIN grupos g ON g.id = es.grupo_id
    LEFT JOIN actividad_asistencias aa ON aa.estudiante_id = es.id
    WHERE (:grupo1 IS NULL OR es.grupo_id = :grupo2)
    GROUP BY es.id, es.nombres, es.apellidos, g.nombre
    ORDER BY $rOrderSql
");
$stmt->execute([':grupo1' => $grupoResumen, ':grupo2' => $grupoResumen]);
$resumenActividades = $stmt->fetchAll();

// Estado combinado de AMBAS tablas: se usa como base para los links de orden de
// las dos, para que cambiar el orden/filtro de una no borre el de la otra.
$allParams = array_filter([
    'sort' => $sortCol !== 'fecha' ? $sortCol : null,
    'dir' => $sortDir !== 'desc' ? $sortDir : null,
    'grupo' => $grupoResumen,
    'rsort' => $rsortCol !== 'nombre' ? $rsortCol : null,
    'rdir' => $rsortDir !== 'asc' ? $rsortDir : null,
], fn($v) => $v !== null);
$volverAQuery = $allParams ? ('?' . http_build_query($allParams)) : '';

$page_title = 'Actividades';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Actividades extracurriculares</h1>
    <?php if (!$editando): ?>
    <button class="btn btn-primary" onclick="document.getElementById('form-nuevo').classList.toggle('hidden')">+ Nueva actividad</button>
    <?php endif; ?>
</div>
<p class="muted">Retiros, procesiones, misas especiales u otras actividades parroquiales que no son los sábados de catequesis. No suman ni restan puntos — es solo un registro de participación.</p>

<?php if (!$grupos): ?>
    <div class="flash error">Primero crea un <a href="grupos.php">grupo</a>.</div>
<?php endif; ?>

<form id="form-nuevo" class="card form-grid <?= $editando ? '' : 'hidden' ?>" method="post" action="actividades.php">
    <input type="hidden" name="accion" value="<?= $editando ? 'editar' : 'crear' ?>">
    <input type="hidden" name="volver_a" value="<?= e($volverAQuery) ?>">
    <?php if ($editando): ?><input type="hidden" name="id" value="<?= (int) $editando['id'] ?>"><?php endif; ?>
    <label>Nombre <input type="text" name="nombre" required placeholder="Ej: Retiro de Adviento" value="<?= e($editando['nombre'] ?? '') ?>"></label>
    <label>Fecha <input type="date" name="fecha" required value="<?= e($editando['fecha'] ?? '') ?>"></label>
    <label>Grupo
        <select name="grupo_id" required>
            <option value="">-- Selecciona --</option>
            <?php foreach ($grupos as $g): ?>
                <option value="<?= (int) $g['id'] ?>" <?= (isset($editando['grupo_id']) && $editando['grupo_id'] == $g['id']) ? 'selected' : '' ?>><?= e($g['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="full">Descripción (opcional) <input type="text" name="descripcion" value="<?= e($editando['descripcion'] ?? '') ?>"></label>
    <div class="full">
        <button class="btn btn-primary" type="submit"><?= $editando ? 'Actualizar' : 'Guardar' ?></button>
        <?php if ($editando): ?><a class="btn btn-muted" href="actividades.php<?= e($volverAQuery) ?>">Cancelar</a><?php endif; ?>
    </div>
</form>

<div class="card">
    <h2>Actividades registradas</h2>
    <table class="table">
        <thead>
            <tr>
                <th><?= sort_link('Nombre', 'nombre', $sortCol, $sortDir, $allParams) ?></th>
                <th><?= sort_link('Fecha', 'fecha', $sortCol, $sortDir, $allParams) ?></th>
                <th><?= sort_link('Grupo', 'grupo', $sortCol, $sortDir, $allParams) ?></th>
                <th><?= sort_link('Asistieron', 'asistieron', $sortCol, $sortDir, $allParams) ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($actividades as $a): ?>
            <tr>
                <td><?= e($a['nombre']) ?></td>
                <td><?= date('d/m/Y', strtotime($a['fecha'])) ?></td>
                <td><?= e($a['grupo_nombre']) ?></td>
                <td><?= (int) $a['asistieron'] ?> / <?= (int) $a['total_registrados'] ?></td>
                <td class="actions">
                    <a class="btn btn-small" href="actividad.php?id=<?= (int) $a['id'] ?>">Tomar asistencia</a>
                    <a class="btn btn-small" href="actividades.php?editar=<?= (int) $a['id'] ?><?= $allParams ? '&' . http_build_query($allParams) : '' ?>">Editar</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$actividades): ?>
            <tr><td colspan="5" class="empty">Aún no hay actividades registradas.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2>Participación por catequizando</h2>
    <form class="form-inline" method="get" action="actividades.php">
        <input type="hidden" name="sort" value="<?= e($sortCol) ?>">
        <input type="hidden" name="dir" value="<?= e($sortDir) ?>">
        <input type="hidden" name="rsort" value="<?= e($rsortCol) ?>">
        <input type="hidden" name="rdir" value="<?= e($rsortDir) ?>">
        <label>Grupo
            <select name="grupo" onchange="this.form.submit()">
                <option value="">Todos</option>
                <?php foreach ($grupos as $g): ?>
                    <option value="<?= (int) $g['id'] ?>" <?= $grupoResumen === (int) $g['id'] ? 'selected' : '' ?>><?= e($g['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
    <table class="table">
        <thead>
            <tr>
                <th><?= sort_link('Catequizando', 'nombre', $rsortCol, $rsortDir, $allParams, 'rsort', 'rdir') ?></th>
                <th><?= sort_link('Grupo', 'grupo', $rsortCol, $rsortDir, $allParams, 'rsort', 'rdir') ?></th>
                <th><?= sort_link('Asistió a', 'asistidas', $rsortCol, $rsortDir, $allParams, 'rsort', 'rdir') ?></th>
                <th><?= sort_link('Total actividades', 'total', $rsortCol, $rsortDir, $allParams, 'rsort', 'rdir') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($resumenActividades as $r): ?>
            <tr>
                <td><?= e($r['nombres']) ?> <?= e($r['apellidos']) ?></td>
                <td><?= e($r['grupo_nombre']) ?></td>
                <td><?= (int) $r['asistidas'] ?></td>
                <td><?= (int) $r['total_actividades'] ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$resumenActividades): ?>
            <tr><td colspan="4" class="empty">No hay datos todavía.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
