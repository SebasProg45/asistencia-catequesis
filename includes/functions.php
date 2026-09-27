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
 * Lee un campo de $_GET/$_POST como texto, de forma segura.
 * Un campo normal (name="nombre") siempre llega como texto, pero cualquiera puede enviar
 * "nombre[]=x" o "nombre[a]=x" y el mismo campo llega como arreglo. PHP 8 no convierte
 * arreglos a texto en funciones como trim(), preg_match() o isset($arr[$clave]): lanza un
 * error fatal que además muestra la ruta del servidor. Esta función corta ese caso de raíz:
 * si no es texto/número, devuelve el valor por defecto en vez de dejar pasar el arreglo.
 */
function campo(array $origen, string $clave, string $defecto = ''): string
{
    $v = $origen[$clave] ?? $defecto;
    return is_scalar($v) ? trim((string) $v) : $defecto;
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
            g.meta_puntos,
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
        GROUP BY es.id, es.nombres, es.apellidos, g.nombre, g.meta_puntos
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

/** Lee un ajuste de la tabla `configuracion` (o el valor por defecto si no existe). */
function config_get(PDO $pdo, string $clave, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT valor FROM configuracion WHERE clave = ?');
    $stmt->execute([$clave]);
    $valor = $stmt->fetchColumn();
    return $valor === false ? $default : (string) $valor;
}

function config_set(PDO $pdo, string $clave, string $valor): void
{
    $stmt = $pdo->prepare('INSERT INTO configuracion (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)');
    $stmt->execute([$clave, $valor]);
}

/**
 * Arma el número para wa.me (solo dígitos, con código de país). Devuelve null si el
 * teléfono no sirve. Un número de 8 dígitos se asume local y se le antepone el código de país.
 */
function telefono_whatsapp(?string $telefono, string $codigoPais): ?string
{
    $digitos = preg_replace('/\D+/', '', (string) $telefono);
    if ($digitos === '' || strlen($digitos) < 7) {
        return null;
    }
    if (strlen($digitos) <= 8) {
        $digitos = preg_replace('/\D+/', '', $codigoPais) . $digitos;
    }
    return $digitos;
}

/**
 * Catequizandos en riesgo:
 *  - racha: sus últimas $rachaMin sesiones registradas seguidas como "no asistió".
 *  - porcentaje: % de puntos por debajo de $pctMin (solo con 3+ sesiones registradas,
 *    para no alarmar por una sola falta al inicio).
 * Solo cuenta las sesiones donde ya hay asistencia registrada para el catequizando.
 */
function obtener_alertas(PDO $pdo, int $rachaMin, float $pctMin): array
{
    $rows = $pdo->query("
        SELECT es.id, es.nombres, es.apellidos, es.nombre_encargado, es.telefono_encargado,
               g.nombre AS grupo_nombre, a.categoria, a.puntos
        FROM estudiantes es
        JOIN grupos g ON g.id = es.grupo_id
        LEFT JOIN asistencias a ON a.estudiante_id = es.id
        LEFT JOIN sesiones s ON s.id = a.sesion_id
        ORDER BY es.id, s.fecha DESC, s.id DESC
    ")->fetchAll();

    $porEstudiante = [];
    foreach ($rows as $r) {
        $id = $r['id'];
        if (!isset($porEstudiante[$id])) {
            $porEstudiante[$id] = [
                'id' => $id, 'nombres' => $r['nombres'], 'apellidos' => $r['apellidos'],
                'grupo_nombre' => $r['grupo_nombre'], 'encargado' => $r['nombre_encargado'],
                'telefono' => $r['telefono_encargado'],
                'total' => 0, 'puntos' => 0.0, 'racha' => 0, 'racha_abierta' => true,
            ];
        }
        if ($r['categoria'] === null) {
            continue; // sin registro de asistencia
        }
        $e = &$porEstudiante[$id];
        $e['total']++;
        $e['puntos'] += (float) $r['puntos'];
        if ($e['racha_abierta']) {
            if ($r['categoria'] === 'ausente') {
                $e['racha']++;
            } else {
                $e['racha_abierta'] = false;
            }
        }
        unset($e);
    }

    $alertas = [];
    foreach ($porEstudiante as $e) {
        $motivos = [];
        if ($rachaMin > 0 && $e['racha'] >= $rachaMin) {
            $motivos[] = $e['racha'] . ' sesiones seguidas sin asistir';
        }
        $pct = $e['total'] > 0 ? ($e['puntos'] / $e['total']) * 100 : null;
        if ($pct !== null && $e['total'] >= 3 && $pct < $pctMin) {
            $motivos[] = 'Asistencia de ' . round($pct) . '%';
        }
        if ($motivos) {
            $e['pct'] = $pct;
            $e['motivos'] = $motivos;
            $alertas[] = $e;
        }
    }

    usort($alertas, fn($a, $b) => [$b['racha'], $a['pct']] <=> [$a['racha'], $b['pct']]);
    return $alertas;
}

/** Número sin ceros sobrantes: 30.0 -> "30", 12.5 -> "12.5" (vacío si es null). */
function fmt_num($valor): string
{
    if ($valor === null || $valor === '') {
        return '';
    }
    return rtrim(rtrim(number_format((float) $valor, 1, '.', ''), '0'), '.');
}

/** Barra de progreso hacia la meta de puntos del grupo ("—" si el grupo no tiene meta). */
function barra_meta(float $puntos, ?float $meta): string
{
    if ($meta === null || $meta <= 0) {
        return '<span class="muted">—</span>';
    }
    $pct = min(100, (int) round(($puntos / $meta) * 100));
    $clase = $puntos >= $meta ? 'meta-ok' : ($pct >= 50 ? 'meta-mid' : 'meta-low');
    return '<div class="meta-wrap" title="' . fmt_num($puntos) . ' de ' . fmt_num($meta) . ' puntos">'
        . '<div class="meta-bar"><div class="meta-fill ' . $clase . '" style="width:' . $pct . '%"></div></div>'
        . '<span class="meta-txt">' . fmt_num($puntos) . ' / ' . fmt_num($meta) . '</span></div>';
}

/** Iniciales para el avatar (ej. "Diego Salazar" -> "DS"). */
function iniciales(?string $nombres, ?string $apellidos): string
{
    return mb_strtoupper(mb_substr(trim((string) $nombres), 0, 1) . mb_substr(trim((string) $apellidos), 0, 1));
}

/** "sábado, 26 de septiembre de 2026" sin depender de la extensión intl. */
function fecha_larga(?int $ts = null): string
{
    $ts = $ts ?? time();
    $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return $dias[(int) date('w', $ts)] . ', ' . date('j', $ts) . ' de ' . $meses[(int) date('n', $ts)] . ' de ' . date('Y', $ts);
}

/** Nivel de color según el % de asistencia: ok >= 80, mid >= 60, low < 60. */
function nivel_pct(?int $pct): string
{
    if ($pct === null) {
        return 'none';
    }
    return $pct >= 80 ? 'ok' : ($pct >= 60 ? 'mid' : 'low');
}

/** Ruta de un recurso estático con su fecha de modificación, para que el navegador no use una copia vieja. */
function asset(string $ruta): string
{
    $archivo = __DIR__ . '/../' . $ruta;
    return $ruta . '?v=' . (is_file($archivo) ? filemtime($archivo) : time());
}
