<?php
/**
 * Respaldo y restauración de la base de datos, sin depender de mysqldump.
 * Formato: un archivo .sql con UNA sentencia por línea (los valores van escapados con
 * PDO::quote, que convierte los saltos de línea en \n), lo que permite validarlo línea a línea.
 */

const RESPALDO_MARCA = '-- RESPALDO asistencia_catequesis';
const RESPALDO_TABLAS = ['grupos', 'configuracion', 'estudiantes', 'sesiones', 'asistencias', 'actividades', 'actividad_asistencias'];

function respaldo_dir(): string
{
    $dir = __DIR__ . '/../respaldos';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    if (!file_exists($dir . '/.htaccess')) {
        file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    return $dir;
}

/** Devuelve el respaldo completo como texto. */
function respaldo_generar(PDO $pdo): string
{
    $out = [RESPALDO_MARCA, '-- Generado: ' . date('Y-m-d H:i:s'), 'SET FOREIGN_KEY_CHECKS=0;'];

    // Se borran en orden inverso y se crean en orden directo (las tablas hijas dependen de las padres).
    foreach (array_reverse(RESPALDO_TABLAS) as $t) {
        $out[] = "DROP TABLE IF EXISTS `$t`;";
    }

    foreach (RESPALDO_TABLAS as $t) {
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
        $out[] = preg_replace('/\s*\n\s*/', ' ', $create) . ';';

        // Las columnas calculadas (ej. asistencias.puntos) se recalculan solas: no se insertan.
        // OJO: "DEFAULT_GENERATED" (ej. creado_en) NO es calculada, solo tiene valor por defecto: sí se respalda.
        $cols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll() as $c) {
            if (!preg_match('/\b(VIRTUAL|STORED) GENERATED\b/i', $c['Extra'])) {
                $cols[] = $c['Field'];
            }
        }
        $lista = '`' . implode('`,`', $cols) . '`';
        $orden = $t === 'configuracion' ? 'clave' : 'id';
        foreach ($pdo->query("SELECT $lista FROM `$t` ORDER BY `$orden`")->fetchAll(PDO::FETCH_NUM) as $fila) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $fila);
            $out[] = "INSERT INTO `$t` ($lista) VALUES (" . implode(',', $vals) . ');';
        }
    }

    $out[] = 'SET FOREIGN_KEY_CHECKS=1;';
    return implode("\n", $out) . "\n";
}

/** ¿La sentencia contiene un ';' fuera de comillas antes de su final? (para impedir varias sentencias en una línea) */
function respaldo_tiene_punto_y_coma_suelto(string $sql): bool
{
    $enComillas = false;
    $n = strlen($sql);
    for ($i = 0; $i < $n - 1; $i++) {
        $ch = $sql[$i];
        if ($enComillas) {
            if ($ch === '\\') {
                $i++;
            } elseif ($ch === "'") {
                $enComillas = false;
            }
        } elseif ($ch === "'") {
            $enComillas = true;
        } elseif ($ch === ';') {
            return true;
        }
    }
    return $enComillas; // comillas sin cerrar = archivo dañado
}

/**
 * Valida TODO el archivo antes de tocar la base de datos. Devuelve la lista de sentencias
 * o lanza RuntimeException con el motivo. Solo acepta las sentencias que genera respaldo_generar().
 */
function respaldo_validar(string $contenido): array
{
    $contenido = str_replace("\r\n", "\n", $contenido);
    $lineas = explode("\n", $contenido);
    if (trim($lineas[0] ?? '') !== RESPALDO_MARCA) {
        throw new RuntimeException('El archivo no es un respaldo de este sistema.');
    }

    $tablas = implode('|', RESPALDO_TABLAS);
    $sentencias = [];
    foreach ($lineas as $i => $linea) {
        $linea = trim($linea);
        if ($linea === '' || str_starts_with($linea, '-- ')) {
            continue;
        }
        $ok = preg_match('/^SET FOREIGN_KEY_CHECKS=[01];$/', $linea)
            || preg_match('/^DROP TABLE IF EXISTS `(' . $tablas . ')`;$/', $linea)
            || preg_match('/^CREATE TABLE `(' . $tablas . ')` \(.*\) ENGINE=[^;]*;$/s', $linea)
            || preg_match('/^INSERT INTO `(' . $tablas . ')` \(`[a-z_`,]+`\) VALUES \(.*\);$/s', $linea);
        if (!$ok || respaldo_tiene_punto_y_coma_suelto($linea)) {
            throw new RuntimeException('El archivo tiene una sentencia no permitida o dañada (línea ' . ($i + 1) . ').');
        }
        $sentencias[] = $linea;
    }

    if (count($sentencias) < 5) {
        throw new RuntimeException('El respaldo está vacío o incompleto.');
    }
    return $sentencias;
}

/** Restaura desde el contenido de un respaldo. Antes guarda una copia automática de lo actual. */
function respaldo_restaurar(PDO $pdo, string $contenido): string
{
    $sentencias = respaldo_validar($contenido);

    $copia = 'antes_de_restaurar_' . date('Ymd_His') . '.sql';
    file_put_contents(respaldo_dir() . '/' . $copia, respaldo_generar($pdo));

    foreach ($sentencias as $sql) {
        $pdo->exec($sql);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    return $copia;
}
