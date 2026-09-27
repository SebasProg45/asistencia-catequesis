<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/functions.php';

// El estado de la vista (filtro, búsqueda, orden) vive en la URL; se conserva tras guardar.
// Se valida contra una lista de caracteres permitidos: "volver_a" llega en un campo oculto
// del formulario y, sin esto, cualquiera podría enviar un POST con un valor arbitrario ahí.
function volver_seguro(): string
{
    $v = $_POST['volver_a'] ?? '';
    return is_string($v) && preg_match('/^\?[A-Za-z0-9_=&%\-.+]*$/', $v) ? $v : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear' || $accion === 'editar') {
        $nombres = campo($_POST, 'nombres');
        $apellidos = campo($_POST, 'apellidos');
        $grupo_id = (int) ($_POST['grupo_id'] ?? 0);
        $nombre_encargado = campo($_POST, 'nombre_encargado') ?: null;
        $telefono_encargado = campo($_POST, 'telefono_encargado') ?: null;
        $observaciones = campo($_POST, 'observaciones') ?: null;

        if ($nombres === '' || $apellidos === '' || $grupo_id <= 0) {
            set_flash('error', 'Nombres, apellidos y grupo son obligatorios.');
        } else {
            try {
                if ($accion === 'crear') {
                    $stmt = $pdo->prepare('INSERT INTO estudiantes (grupo_id, nombres, apellidos, nombre_encargado, telefono_encargado, observaciones) VALUES (?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$grupo_id, $nombres, $apellidos, $nombre_encargado, $telefono_encargado, $observaciones]);
                    set_flash('success', 'Catequizando registrado.');
                } else {
                    $id = (int) ($_POST['id'] ?? 0);
                    $stmt = $pdo->prepare('UPDATE estudiantes SET grupo_id=?, nombres=?, apellidos=?, nombre_encargado=?, telefono_encargado=?, observaciones=? WHERE id=?');
                    $stmt->execute([$grupo_id, $nombres, $apellidos, $nombre_encargado, $telefono_encargado, $observaciones, $id]);
                    set_flash('success', 'Datos actualizados.');
                }
            } catch (PDOException $e) {
                set_flash('error', 'No se pudo guardar. Verifica los datos e intenta de nuevo.');
            }
        }
    } elseif ($accion === 'borrar') {
        $id = (int) ($_POST['id'] ?? 0);
        try {
            // Sus asistencias (regulares y de actividades) se eliminan en cascada por las FK.
            $stmt = $pdo->prepare('DELETE FROM estudiantes WHERE id = ?');
            $stmt->execute([$id]);
            set_flash('success', 'Catequizando eliminado.');
        } catch (PDOException $e) {
            set_flash('error', 'No se pudo eliminar al catequizando. Intenta de nuevo.');
        }
    }

    // Conserva el filtro/orden que el usuario tenía activo antes de crear/editar.
    redirect('estudiantes.php' . volver_seguro());
}

$grupos = $pdo->query('SELECT * FROM grupos ORDER BY nombre')->fetchAll();

$grupoFiltro = isset($_GET['grupo']) ? (int) $_GET['grupo'] : 0;
$busqueda = campo($_GET, 'q');
$sortCol = campo($_GET, 'sort', 'nombre');
$sortDir = campo($_GET, 'dir', 'asc') === 'desc' ? 'desc' : 'asc';

$sortColumns = [
    'nombre' => ['es.nombres', 'es.apellidos'],
    'grupo' => ['g.nombre'],
    'encargado' => ['es.nombre_encargado'],
    'telefono' => ['es.telefono_encargado'],
];
if (!isset($sortColumns[$sortCol])) {
    $sortCol = 'nombre';
}

$sql = "SELECT es.*, g.nombre AS grupo_nombre FROM estudiantes es JOIN grupos g ON g.id = es.grupo_id WHERE 1=1";
$params = [];
if ($grupoFiltro) {
    $sql .= ' AND es.grupo_id = ?';
    $params[] = $grupoFiltro;
}
if ($busqueda !== '') {
    $sql .= ' AND (es.nombres LIKE ? OR es.apellidos LIKE ?)';
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
}
// Aplica la dirección a CADA columna del criterio (si no, en "nombre" -> [nombres, apellidos]
// el DESC solo afectaba al último campo y quedaba siempre ascendente).
$dirSql = strtoupper($sortDir);
$orderParts = array_map(fn($col) => "$col $dirSql", $sortColumns[$sortCol]);
$sql .= ' ORDER BY ' . implode(', ', $orderParts);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$estudiantes = $stmt->fetchAll();

// URL actual (filtro + orden) para volver aquí tras crear/editar o cancelar.
$volverA = array_filter([
    'grupo' => $grupoFiltro ?: null,
    'q' => $busqueda !== '' ? $busqueda : null,
    'sort' => $sortCol !== 'nombre' ? $sortCol : null,
    'dir' => $sortDir !== 'asc' ? $sortDir : null,
], fn($v) => $v !== null);
$volverAQuery = $volverA ? ('?' . http_build_query($volverA)) : '';

$editando = null;
if (!empty($_GET['editar'])) {
    $stmt = $pdo->prepare('SELECT * FROM estudiantes WHERE id = ?');
    $stmt->execute([(int) $_GET['editar']]);
    $editando = $stmt->fetch() ?: null;
}

$page_title = 'Catequizandos';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Catequizandos</h1>
    <?php if (!$editando): ?>
    <div class="actions-row">
        <a class="btn" href="promocion.php">Pasar de grupo</a>
        <button class="btn btn-primary" onclick="document.getElementById('form-nuevo').classList.toggle('hidden')">+ Nuevo catequizando</button>
    </div>
    <?php endif; ?>
</div>

<?php if (!$grupos): ?>
    <div class="flash error">Primero crea un <a href="grupos.php">grupo</a> antes de registrar catequizandos.</div>
<?php endif; ?>

<form id="form-nuevo" class="card form-grid <?= $editando ? '' : 'hidden' ?>" method="post" action="estudiantes.php">
    <input type="hidden" name="accion" value="<?= $editando ? 'editar' : 'crear' ?>">
    <input type="hidden" name="volver_a" value="<?= e($volverAQuery) ?>">
    <?php if ($editando): ?><input type="hidden" name="id" value="<?= (int) $editando['id'] ?>"><?php endif; ?>
    <label>Nombres <input type="text" name="nombres" required value="<?= e($editando['nombres'] ?? '') ?>"></label>
    <label>Apellidos <input type="text" name="apellidos" required value="<?= e($editando['apellidos'] ?? '') ?>"></label>
    <label>Grupo
        <select name="grupo_id" required>
            <option value="">-- Selecciona --</option>
            <?php foreach ($grupos as $g): ?>
                <option value="<?= (int) $g['id'] ?>" <?= (isset($editando['grupo_id']) && $editando['grupo_id'] == $g['id']) ? 'selected' : '' ?>><?= e($g['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Nombre del encargado <input type="text" name="nombre_encargado" value="<?= e($editando['nombre_encargado'] ?? '') ?>"></label>
    <label>Teléfono del encargado <input type="text" name="telefono_encargado" value="<?= e($editando['telefono_encargado'] ?? '') ?>"></label>
    <label class="full">Observaciones <input type="text" name="observaciones" value="<?= e($editando['observaciones'] ?? '') ?>"></label>
    <div class="full">
        <button class="btn btn-primary" type="submit"><?= $editando ? 'Actualizar' : 'Guardar' ?></button>
        <?php if ($editando): ?><a class="btn btn-muted" href="estudiantes.php<?= e($volverAQuery) ?>">Cancelar</a><?php endif; ?>
        <?php if ($editando): ?>
            <button class="btn btn-danger" type="submit" form="form-borrar"
                onclick="return confirm('¿Estás seguro que quieres borrar a <?= e(addslashes($editando['nombres'] . ' ' . $editando['apellidos'])) ?>?\n\nSe eliminará también todo su historial de asistencia. Esta acción no se puede deshacer.');">Borrar catequizando</button>
        <?php endif; ?>
    </div>
</form>
<?php if ($editando): ?>
<form id="form-borrar" method="post" action="estudiantes.php">
    <input type="hidden" name="accion" value="borrar">
    <input type="hidden" name="id" value="<?= (int) $editando['id'] ?>">
    <input type="hidden" name="volver_a" value="<?= e($volverAQuery) ?>">
</form>
<?php endif; ?>

<form class="card form-inline" method="get" action="estudiantes.php">
    <input type="hidden" name="sort" value="<?= e($sortCol) ?>">
    <input type="hidden" name="dir" value="<?= e($sortDir) ?>">
    <label>Grupo
        <select name="grupo">
            <option value="">Todos</option>
            <?php foreach ($grupos as $g): ?>
                <option value="<?= (int) $g['id'] ?>" <?= $grupoFiltro == $g['id'] ? 'selected' : '' ?>><?= e($g['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Buscar <input type="text" name="q" value="<?= e($busqueda) ?>" placeholder="Nombre o apellido"></label>
    <button class="btn" type="submit">Filtrar</button>
    <a class="btn" href="estudiantes_export.php?<?= http_build_query(['grupo' => $grupoFiltro ?: '', 'q' => $busqueda]) ?>">📊 Exportar a Excel</a>
    <?php if ($grupoFiltro): ?><a class="btn" href="boletin.php?grupo=<?= (int) $grupoFiltro ?>">🖨 Boletines del grupo</a><?php endif; ?>
</form>

<div class="card">
<table class="table">
    <thead>
        <?php $sortBase = array_filter(['grupo' => $grupoFiltro ?: null, 'q' => $busqueda !== '' ? $busqueda : null], fn($v) => $v !== null); ?>
        <tr>
            <th><?= sort_link('Nombre', 'nombre', $sortCol, $sortDir, $sortBase) ?></th>
            <th><?= sort_link('Grupo', 'grupo', $sortCol, $sortDir, $sortBase) ?></th>
            <th><?= sort_link('Encargado', 'encargado', $sortCol, $sortDir, $sortBase) ?></th>
            <th><?= sort_link('Teléfono', 'telefono', $sortCol, $sortDir, $sortBase) ?></th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($estudiantes as $es): ?>
        <tr>
            <td><?= e($es['nombres']) ?> <?= e($es['apellidos']) ?></td>
            <td><?= e($es['grupo_nombre']) ?></td>
            <td><?= e($es['nombre_encargado']) ?></td>
            <td><?= e($es['telefono_encargado']) ?></td>
            <td class="actions">
                <a class="btn btn-small" href="estudiantes.php?editar=<?= (int) $es['id'] ?><?= $volverA ? '&' . http_build_query($volverA) : '' ?>">Editar</a>
                <a class="btn btn-small" href="boletin.php?id=<?= (int) $es['id'] ?>">Boletín</a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$estudiantes): ?>
            <tr><td colspan="5" class="empty">No hay catequizandos que coincidan.</td></tr>
        <?php endif; ?>
    </tbody>
</table>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
