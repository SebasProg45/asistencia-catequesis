<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/functions.php';

$grupos = $pdo->query('SELECT * FROM grupos ORDER BY nombre')->fetchAll();

$grupoFiltro = isset($_GET['grupo']) && $_GET['grupo'] !== '' ? (int) $_GET['grupo'] : null;
$desde = $_GET['desde'] ?? '';
$hasta = $_GET['hasta'] ?? '';

$resumen = obtener_resumen_asistencia($pdo, $grupoFiltro, $desde ?: null, $hasta ?: null);

// --- Orden de la tabla de sumatoria (en memoria, incluye el % calculado) ---
$sortCol = $_GET['sort'] ?? 'ausencias';
$sortDir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$sortFns = [
    'catequizando' => fn($r) => mb_strtolower($r['nombres'] . ' ' . $r['apellidos']),
    'grupo' => fn($r) => mb_strtolower($r['grupo_nombre']),
    'sesiones' => fn($r) => (int) $r['total_sesiones'],
    'completas' => fn($r) => (int) $r['completas'],
    'solo_catequesis' => fn($r) => (int) $r['solo_catequesis'],
    'solo_misa' => fn($r) => (int) $r['solo_misa'],
    'ausencias' => fn($r) => (int) $r['ausencias'],
    'puntos' => fn($r) => (float) $r['puntos_totales'],
    'pct' => fn($r) => $r['total_sesiones'] > 0 ? $r['puntos_totales'] / $r['total_sesiones'] : -1,
];
if (!isset($sortFns[$sortCol])) {
    $sortCol = 'ausencias';
}
usort($resumen, function ($a, $b) use ($sortFns, $sortCol, $sortDir) {
    $va = $sortFns[$sortCol]($a);
    $vb = $sortFns[$sortCol]($b);
    $cmp = is_string($va) ? strcasecmp($va, $vb) : ($va <=> $vb);
    if ($cmp === 0) {
        $cmp = strcasecmp($a['nombres'], $b['nombres']);
    }
    return $sortDir === 'asc' ? $cmp : -$cmp;
});

// --- Orden de la tabla de sesiones recientes (consulta SQL aparte) ---
$ssortCol = $_GET['ssort'] ?? 'fecha';
$ssortDir = ($_GET['sdir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
// Nombres de columna SIN prefijo: el ORDER BY externo opera sobre la subconsulta
// "recientes", que ya expone fecha/tema/grupo_nombre sin los alias s./g. originales.
$ssortColumns = [
    'fecha' => 'fecha',
    'grupo' => 'grupo_nombre',
    'tema' => 'tema',
];
if (!isset($ssortColumns[$ssortCol])) {
    $ssortCol = 'fecha';
}
$sOrderSql = $ssortColumns[$ssortCol] . ' ' . strtoupper($ssortDir);

// Primero se toman las 15 sesiones MÁS RECIENTES (fecha DESC), y solo después
// se reordena ese conjunto fijo según la columna elegida — si el LIMIT se
// aplicara después del ORDER BY dinámico, ordenar por "Tema" (o por fecha
// ascendente) mostraría las 15 más antiguas/alfabéticamente primeras de TODO
// el historial en vez de las 15 recientes, bajo un título que dice "recientes".
$sesionesRecientes = $pdo->query("
    SELECT * FROM (
        SELECT s.id, s.fecha, s.tema, g.nombre AS grupo_nombre
        FROM sesiones s JOIN grupos g ON g.id = s.grupo_id
        ORDER BY s.fecha DESC
        LIMIT 15
    ) recientes
    ORDER BY $sOrderSql
")->fetchAll();

// Parámetros a conservar en todos los links de orden de esta página.
$sortBase = array_filter([
    'grupo' => $grupoFiltro,
    'desde' => $desde !== '' ? $desde : null,
    'hasta' => $hasta !== '' ? $hasta : null,
    'sort' => $sortCol !== 'ausencias' ? $sortCol : null,
    'dir' => $sortDir !== 'desc' ? $sortDir : null,
    'ssort' => $ssortCol !== 'fecha' ? $ssortCol : null,
    'sdir' => $ssortDir !== 'desc' ? $ssortDir : null,
], fn($v) => $v !== null);

$page_title = 'Reportes';
require __DIR__ . '/includes/header.php';
?>
<h1>Reportes de asistencia</h1>

<form class="card form-inline" method="get" action="reportes.php">
    <?php // Conserva el orden activo de ambas tablas al filtrar (si no, "Filtrar" lo reseteaba). ?>
    <input type="hidden" name="sort" value="<?= e($sortCol) ?>">
    <input type="hidden" name="dir" value="<?= e($sortDir) ?>">
    <input type="hidden" name="ssort" value="<?= e($ssortCol) ?>">
    <input type="hidden" name="sdir" value="<?= e($ssortDir) ?>">
    <label>Grupo
        <select name="grupo">
            <option value="">Todos</option>
            <?php foreach ($grupos as $g): ?>
                <option value="<?= (int) $g['id'] ?>" <?= $grupoFiltro === (int) $g['id'] ? 'selected' : '' ?>><?= e($g['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Desde <input type="date" name="desde" value="<?= e($desde) ?>"></label>
    <label>Hasta <input type="date" name="hasta" value="<?= e($hasta) ?>"></label>
    <button class="btn btn-primary" type="submit">Filtrar</button>
    <a class="btn" href="reportes_export.php?<?= http_build_query(['grupo' => $grupoFiltro, 'desde' => $desde, 'hasta' => $hasta]) ?>">📊 Exportar a Excel</a>
</form>

<div class="card">
    <h2>Sumatoria de puntos por catequizando</h2>
    <p class="muted">1 punto = misa y catequesis · 0.5 = solo catequesis o solo misa · 0 = no asistió.</p>
    <table class="table">
        <thead>
            <tr>
                <th><?= sort_link('Catequizando', 'catequizando', $sortCol, $sortDir, $sortBase) ?></th>
                <th><?= sort_link('Grupo', 'grupo', $sortCol, $sortDir, $sortBase) ?></th>
                <th><?= sort_link('Sesiones', 'sesiones', $sortCol, $sortDir, $sortBase) ?></th>
                <th><?= sort_link('Misa y Catequesis', 'completas', $sortCol, $sortDir, $sortBase) ?></th>
                <th><?= sort_link('Solo Catequesis', 'solo_catequesis', $sortCol, $sortDir, $sortBase) ?></th>
                <th><?= sort_link('Solo Misa', 'solo_misa', $sortCol, $sortDir, $sortBase) ?></th>
                <th><?= sort_link('No asistió', 'ausencias', $sortCol, $sortDir, $sortBase) ?></th>
                <th><?= sort_link('Puntos', 'puntos', $sortCol, $sortDir, $sortBase) ?></th>
                <th><?= sort_link('% Asistencia', 'pct', $sortCol, $sortDir, $sortBase) ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($resumen as $r): ?>
            <?php $pct = $r['total_sesiones'] > 0 ? round(($r['puntos_totales'] / $r['total_sesiones']) * 100) : null; ?>
            <tr>
                <td><?= e($r['nombres']) ?> <?= e($r['apellidos']) ?></td>
                <td><?= e($r['grupo_nombre']) ?></td>
                <td><?= (int) $r['total_sesiones'] ?></td>
                <td><?= (int) $r['completas'] ?></td>
                <td><?= (int) $r['solo_catequesis'] ?></td>
                <td><?= (int) $r['solo_misa'] ?></td>
                <td><span class="badge <?= $r['ausencias'] > 0 ? 'badge-off' : 'badge-ok' ?>"><?= (int) $r['ausencias'] ?></span></td>
                <td><?= number_format((float) $r['puntos_totales'], 1) ?></td>
                <td><?= $pct !== null ? $pct . '%' : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$resumen): ?>
            <tr><td colspan="9" class="empty">No hay datos para los filtros seleccionados.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2>Sesiones recientes</h2>
    <table class="table">
        <thead><tr>
            <th><?= sort_link('Fecha', 'fecha', $ssortCol, $ssortDir, $sortBase, 'ssort', 'sdir') ?></th>
            <th><?= sort_link('Grupo', 'grupo', $ssortCol, $ssortDir, $sortBase, 'ssort', 'sdir') ?></th>
            <th><?= sort_link('Tema', 'tema', $ssortCol, $ssortDir, $sortBase, 'ssort', 'sdir') ?></th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($sesionesRecientes as $s): ?>
            <tr>
                <td><?= date('d/m/Y', strtotime($s['fecha'])) ?></td>
                <td><?= e($s['grupo_nombre']) ?></td>
                <td><?= e($s['tema']) ?></td>
                <td><a class="btn btn-small" href="reportes_sesion.php?id=<?= (int) $s['id'] ?>">Ver detalle</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$sesionesRecientes): ?>
            <tr><td colspan="4" class="empty">Aún no hay sesiones registradas.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
