<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear' || $accion === 'editar') {
        $nombre = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $dia_semana = trim($_POST['dia_semana'] ?? '');

        if ($nombre === '') {
            set_flash('error', 'El nombre del grupo es obligatorio.');
        } else {
            try {
                if ($accion === 'crear') {
                    $stmt = $pdo->prepare('INSERT INTO grupos (nombre, descripcion, dia_semana) VALUES (?, ?, ?)');
                    $stmt->execute([$nombre, $descripcion ?: null, $dia_semana ?: null]);
                    set_flash('success', 'Grupo creado correctamente.');
                } else {
                    $id = (int) ($_POST['id'] ?? 0);
                    $stmt = $pdo->prepare('UPDATE grupos SET nombre = ?, descripcion = ?, dia_semana = ? WHERE id = ?');
                    $stmt->execute([$nombre, $descripcion ?: null, $dia_semana ?: null, $id]);
                    set_flash('success', 'Grupo actualizado.');
                }
            } catch (PDOException $e) {
                set_flash('error', 'No se pudo guardar el grupo. Verifica los datos e intenta de nuevo.');
            }
        }
    }

    redirect('grupos.php');
}

$editando = null;
if (!empty($_GET['editar'])) {
    $stmt = $pdo->prepare('SELECT * FROM grupos WHERE id = ?');
    $stmt->execute([(int) $_GET['editar']]);
    $editando = $stmt->fetch() ?: null;
}

$sortCol = $_GET['sort'] ?? 'nombre';
$sortDir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$sortColumns = [
    'nombre' => 'g.nombre',
    'dia' => 'g.dia_semana',
    'descripcion' => 'g.descripcion',
    'catequizandos' => 'total_estudiantes',
];
if (!isset($sortColumns[$sortCol])) {
    $sortCol = 'nombre';
}
$orderSql = $sortColumns[$sortCol] . ' ' . strtoupper($sortDir);

$grupos = $pdo->query("
    SELECT g.*, COUNT(es.id) AS total_estudiantes
    FROM grupos g
    LEFT JOIN estudiantes es ON es.grupo_id = g.id
    GROUP BY g.id
    ORDER BY $orderSql
")->fetchAll();

$page_title = 'Grupos';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Grupos de catequesis</h1>
    <?php if (!$editando): ?>
    <button class="btn btn-primary" onclick="document.getElementById('form-nuevo').classList.toggle('hidden')">+ Nuevo grupo</button>
    <?php endif; ?>
</div>

<form id="form-nuevo" class="card form-inline <?= $editando ? '' : 'hidden' ?>" method="post" action="grupos.php">
    <input type="hidden" name="accion" value="<?= $editando ? 'editar' : 'crear' ?>">
    <?php if ($editando): ?><input type="hidden" name="id" value="<?= (int) $editando['id'] ?>"><?php endif; ?>
    <label>Nombre <input type="text" name="nombre" required placeholder="Ej: Primera Comunión Nivel 1" value="<?= e($editando['nombre'] ?? '') ?>"></label>
    <label>Día <input type="text" name="dia_semana" placeholder="Sábado" value="<?= e($editando['dia_semana'] ?? '') ?>"></label>
    <label>Descripción <input type="text" name="descripcion" placeholder="Opcional" value="<?= e($editando['descripcion'] ?? '') ?>"></label>
    <button class="btn btn-primary" type="submit"><?= $editando ? 'Actualizar' : 'Guardar' ?></button>
    <?php if ($editando): ?><a class="btn btn-muted" href="grupos.php">Cancelar</a><?php endif; ?>
</form>

<div class="card">
<table class="table">
    <thead>
        <tr>
            <th><?= sort_link('Nombre', 'nombre', $sortCol, $sortDir, []) ?></th>
            <th><?= sort_link('Día', 'dia', $sortCol, $sortDir, []) ?></th>
            <th><?= sort_link('Descripción', 'descripcion', $sortCol, $sortDir, []) ?></th>
            <th><?= sort_link('Catequizandos', 'catequizandos', $sortCol, $sortDir, []) ?></th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($grupos as $g): ?>
        <tr>
            <td><?= e($g['nombre']) ?></td>
            <td><?= e($g['dia_semana']) ?></td>
            <td><?= e($g['descripcion']) ?></td>
            <td><?= (int) $g['total_estudiantes'] ?></td>
            <td class="actions">
                <a class="btn btn-small" href="grupos.php?editar=<?= (int) $g['id'] ?>">Editar</a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$grupos): ?>
            <tr><td colspan="5" class="empty">Aún no hay grupos. Crea el primero arriba.</td></tr>
        <?php endif; ?>
    </tbody>
</table>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
