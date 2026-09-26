<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/xlsx.php';

$grupoFiltro = isset($_GET['grupo']) ? (int) $_GET['grupo'] : 0;
$busqueda = trim($_GET['q'] ?? '');

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
$sql .= ' ORDER BY es.nombres, es.apellidos';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$estudiantes = $stmt->fetchAll();

$encabezados = ['Nombres', 'Apellidos', 'Grupo', 'Encargado', 'Teléfono', 'Observaciones'];

$filas = [];
foreach ($estudiantes as $es) {
    $filas[] = [
        $es['nombres'],
        $es['apellidos'],
        $es['grupo_nombre'],
        $es['nombre_encargado'],
        $es['telefono_encargado'],
        $es['observaciones'],
    ];
}

// Columna 4 (Teléfono) siempre como texto, para no perder ceros a la izquierda ni el guion.
descargar_xlsx('catequizandos_' . date('Y-m-d') . '.xlsx', 'Catequizandos', $encabezados, $filas, [4]);
