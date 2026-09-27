<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/respaldo.php';

// --- Descargar un respaldo nuevo (se genera al momento) ---
if (($_GET['accion'] ?? '') === 'descargar') {
    $sql = respaldo_generar($pdo);
    $nombre = 'respaldo_asistencia_' . date('Y-m-d_His') . '.sql';
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    header('Content-Length: ' . strlen($sql));
    echo $sql;
    exit;
}

// --- Descargar una copia guardada en la carpeta respaldos/ ---
if (isset($_GET['bajar'])) {
    $archivo = basename((string) $_GET['bajar']);
    $ruta = respaldo_dir() . '/' . $archivo;
    if (preg_match('/^[A-Za-z0-9_\-]+\.sql$/', $archivo) && is_file($ruta)) {
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $archivo . '"');
        header('Content-Length: ' . filesize($ruta));
        readfile($ruta);
        exit;
    }
    set_flash('error', 'Ese archivo de respaldo no existe.');
    redirect('respaldo.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'guardar_copia') {
        $nombre = 'respaldo_asistencia_' . date('Y-m-d_His') . '.sql';
        file_put_contents(respaldo_dir() . '/' . $nombre, respaldo_generar($pdo));
        set_flash('success', "Copia guardada en la carpeta respaldos/ ($nombre).");
    } elseif ($accion === 'restaurar') {
        $archivo = $_FILES['archivo'] ?? null;
        if (!$archivo || $archivo['error'] !== UPLOAD_ERR_OK || $archivo['size'] <= 0) {
            set_flash('error', 'Selecciona un archivo de respaldo (.sql) válido.');
        } else {
            try {
                $copia = respaldo_restaurar($pdo, file_get_contents($archivo['tmp_name']));
                set_flash('success', "Respaldo restaurado. Los datos anteriores se guardaron antes en respaldos/$copia.");
            } catch (RuntimeException $e) {
                set_flash('error', $e->getMessage() . ' No se modificó nada.');
            } catch (PDOException $e) {
                set_flash('error', 'Falló la restauración a la mitad. Tus datos anteriores están en la carpeta respaldos/ (archivo antes_de_restaurar_...).');
            }
        }
    }
    redirect('respaldo.php');
}

$copias = [];
foreach (glob(respaldo_dir() . '/*.sql') ?: [] as $ruta) {
    $copias[] = ['nombre' => basename($ruta), 'tam' => filesize($ruta), 'fecha' => filemtime($ruta)];
}
usort($copias, fn($a, $b) => $b['fecha'] <=> $a['fecha']);

$page_title = 'Respaldo';
require __DIR__ . '/includes/header.php';
?>
<h1>Copia de seguridad</h1>
<p class="muted">Todos los datos viven en MySQL dentro de esta computadora. Descarga un respaldo cada cierto tiempo y guárdalo en otro lugar (USB, OneDrive, correo).</p>

<div class="card">
    <h2>Descargar respaldo</h2>
    <p class="muted">Genera un archivo .sql con todos los grupos, catequizandos, sesiones, asistencias y actividades.</p>
    <div class="actions-row">
        <a class="btn btn-primary" href="respaldo.php?accion=descargar">⬇ Descargar respaldo ahora</a>
        <form method="post" action="respaldo.php" class="inline-form">
            <input type="hidden" name="accion" value="guardar_copia">
            <button class="btn" type="submit">Guardar copia en la carpeta del sistema</button>
        </form>
    </div>
</div>

<div class="card">
    <h2>Restaurar desde un respaldo</h2>
    <p class="muted"><strong>Reemplaza todos los datos actuales</strong> por los del archivo. Antes de hacerlo se guarda automáticamente una copia de lo que hay ahora.</p>
    <form method="post" action="respaldo.php" enctype="multipart/form-data" class="form-inline"
          onsubmit="return confirm('¿Estás seguro? Se reemplazarán TODOS los datos actuales por los del archivo seleccionado.');">
        <input type="hidden" name="accion" value="restaurar">
        <label>Archivo de respaldo (.sql) <input type="file" name="archivo" accept=".sql" required></label>
        <button class="btn btn-danger" type="submit">Restaurar</button>
    </form>
</div>

<div class="card">
    <h2>Copias guardadas en la carpeta del sistema</h2>
    <table class="table">
        <thead><tr><th>Archivo</th><th>Fecha</th><th>Tamaño</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($copias as $c): ?>
            <tr>
                <td><?= e($c['nombre']) ?></td>
                <td><?= date('d/m/Y H:i', $c['fecha']) ?></td>
                <td><?= number_format($c['tam'] / 1024, 1) ?> KB</td>
                <td><a class="btn btn-small" href="respaldo.php?bajar=<?= e(rawurlencode($c['nombre'])) ?>">Descargar</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$copias): ?><tr><td colspan="4" class="empty">Aún no hay copias guardadas.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
