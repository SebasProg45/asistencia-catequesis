<?php

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function redirect(string $to): void
{
    header("Location: {$to}");
    exit;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Genera un encabezado de columna ordenable (estilo Snowflake): un link que
 * alterna asc/desc y muestra una flecha activa o un ícono neutro de "sin ordenar".
 * $baseParams son los demás filtros/orden de la página que deben conservarse en la URL.
 */
function sort_link(string $label, string $column, string $currentSort, string $currentDir, array $baseParams, string $sortKey = 'sort', string $dirKey = 'dir'): string
{
    $isActive = $currentSort === $column;
    $newDir = ($isActive && $currentDir === 'asc') ? 'desc' : 'asc';
    $params = array_merge($baseParams, [$sortKey => $column, $dirKey => $newDir]);
    $url = '?' . http_build_query($params);

    if ($isActive && $currentDir === 'asc') {
        $icon = '<svg class="sort-icon is-active" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 15l6-6 6 6"/></svg>';
    } elseif ($isActive) {
        $icon = '<svg class="sort-icon is-active" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
    } else {
        $icon = '<svg class="sort-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10l5-5 5 5"/><path d="M7 14l5 5 5-5"/></svg>';
    }

    return '<a class="th-sort' . ($isActive ? ' is-active' : '') . '" href="' . e($url) . '">' . e($label) . $icon . '</a>';
}

/**
 * Devuelve, por estudiante, la sumatoria de puntos de asistencia.
 * Puntos por sesión: 1.0 = misa y catequesis, 0.5 = solo catequesis o solo misa, 0.0 = no asistió.
 * Permite filtrar opcionalmente por grupo (el grupo ACTUAL del catequizando, solo para decidir
 * qué filas mostrar) y rango de fechas.
 *
 * Importante: `asistencias` se une por estudiante_id (su propio historial, sin importar a qué
 * grupo pertenezca HOY) — si se uniera por es.grupo_id, cambiar a un catequizando de grupo
 * (ej. al promoverlo de nivel) borraría en silencio todo su historial de los reportes, porque
 * ya no calzaría con sesiones del grupo nuevo. El rango de fechas se aplica dentro de cada
 * SUM/COUNT (no en el JOIN) para no reintroducir el bug de que el filtro de fechas no filtrara nada.
 */
function obtener_resumen_asistencia(PDO $pdo, ?int $grupoId = null, ?string $desde = null, ?string $hasta = null): array
{
    $sql = "
        SELECT
            es.id AS estudiante_id,
            es.nombres,
            es.apellidos,
            g.nombre AS grupo_nombre,
            SUM(CASE WHEN a.id IS NOT NULL
                AND (:desde1 IS NULL OR s.fecha >= :desde2)
                AND (:hasta1 IS NULL OR s.fecha <= :hasta2)
                THEN 1 ELSE 0 END) AS total_sesiones,
            SUM(CASE WHEN a.categoria = 'completo'
                AND (:desde1 IS NULL OR s.fecha >= :desde2)
                AND (:hasta1 IS NULL OR s.fecha <= :hasta2)
                THEN 1 ELSE 0 END) AS completas,
            SUM(CASE WHEN a.categoria = 'catequesis'
                AND (:desde1 IS NULL OR s.fecha >= :desde2)
                AND (:hasta1 IS NULL OR s.fecha <= :hasta2)
                THEN 1 ELSE 0 END) AS solo_catequesis,
            SUM(CASE WHEN a.categoria = 'misa'
                AND (:desde1 IS NULL OR s.fecha >= :desde2)
                AND (:hasta1 IS NULL OR s.fecha <= :hasta2)
                THEN 1 ELSE 0 END) AS solo_misa,
            SUM(CASE WHEN a.categoria = 'ausente'
                AND (:desde1 IS NULL OR s.fecha >= :desde2)
                AND (:hasta1 IS NULL OR s.fecha <= :hasta2)
                THEN 1 ELSE 0 END) AS ausencias,
            COALESCE(SUM(CASE WHEN (:desde1 IS NULL OR s.fecha >= :desde2)
                AND (:hasta1 IS NULL OR s.fecha <= :hasta2)
                THEN a.puntos ELSE 0 END), 0) AS puntos_totales
        FROM estudiantes es
        JOIN grupos g ON g.id = es.grupo_id
        LEFT JOIN asistencias a ON a.estudiante_id = es.id
        LEFT JOIN sesiones s ON s.id = a.sesion_id
        WHERE (:grupo1 IS NULL OR es.grupo_id = :grupo2)
        GROUP BY es.id, es.nombres, es.apellidos, g.nombre
        ORDER BY ausencias DESC, es.nombres ASC, es.apellidos ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':desde1' => $desde, ':desde2' => $desde,
        ':hasta1' => $hasta, ':hasta2' => $hasta,
        ':grupo1' => $grupoId, ':grupo2' => $grupoId,
    ]);

    return $stmt->fetchAll();
}

/**
 * Devuelve, por estudiante (indexado por estudiante_id), la participación en
 * actividades extracurriculares/parroquiales. No tiene relación con los puntos
 * de asistencia regular — es un conteo aparte (asistió / total de actividades).
 *
 * Igual que obtener_resumen_asistencia(): se une por estudiante_id (su propio
 * historial) y el rango de fechas se aplica dentro del SUM, no en el JOIN, para
 * que un cambio de grupo no borre en silencio la participación ya registrada.
 */
function obtener_resumen_actividades(PDO $pdo, ?int $grupoId = null, ?string $desde = null, ?string $hasta = null): array
{
    $sql = "
        SELECT
            es.id AS estudiante_id,
            SUM(CASE WHEN aa.id IS NOT NULL
                AND (:desde1 IS NULL OR act.fecha >= :desde2)
                AND (:hasta1 IS NULL OR act.fecha <= :hasta2)
                THEN 1 ELSE 0 END) AS total_actividades,
            COALESCE(SUM(CASE WHEN (:desde1 IS NULL OR act.fecha >= :desde2)
                AND (:hasta1 IS NULL OR act.fecha <= :hasta2)
                THEN aa.asistio ELSE 0 END), 0) AS actividades_asistidas
        FROM estudiantes es
        LEFT JOIN actividad_asistencias aa ON aa.estudiante_id = es.id
        LEFT JOIN actividades act ON act.id = aa.actividad_id
        WHERE (:grupo1 IS NULL OR es.grupo_id = :grupo2)
        GROUP BY es.id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':desde1' => $desde, ':desde2' => $desde,
        ':hasta1' => $hasta, ':hasta2' => $hasta,
        ':grupo1' => $grupoId, ':grupo2' => $grupoId,
    ]);

    $porEstudiante = [];
    foreach ($stmt->fetchAll() as $fila) {
        $porEstudiante[$fila['estudiante_id']] = $fila;
    }

    return $porEstudiante;
}
