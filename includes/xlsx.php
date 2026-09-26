<?php
/**
 * Generador mínimo de archivos .xlsx (Excel real, no CSV) usando solo PHP + ZipArchive.
 * No depende de librerías externas ni de Composer.
 */

function xlsx_columna(int $indiceCero): string
{
    $n = $indiceCero + 1;
    $letra = '';
    while ($n > 0) {
        $resto = ($n - 1) % 26;
        $letra = chr(65 + $resto) . $letra;
        $n = intdiv($n - 1, 26);
    }
    return $letra;
}

function xlsx_escape(string $valor): string
{
    $valor = str_replace(["\r\n", "\r"], "\n", $valor);
    // XML 1.0 prohíbe estos caracteres de control incluso escapados como entidad —
    // si un nombre pegado con caracteres raros los trae, corrompería el .xlsx sin este filtro.
    $valor = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $valor);
    return htmlspecialchars($valor, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function xlsx_celda(string $ref, $valor, bool $esTexto, int $estilo = 0): string
{
    if ($valor === null || $valor === '') {
        return $estilo ? "<c r=\"{$ref}\" s=\"{$estilo}\"/>" : '';
    }

    $sAttr = $estilo ? " s=\"{$estilo}\"" : '';

    // Quita separador de miles antes de evaluar si es numérico: number_format() puede
    // devolver algo como "1,234.5", que is_numeric() rechaza por la coma.
    $valorNumerico = is_string($valor) ? str_replace(',', '', $valor) : $valor;
    if (!$esTexto && is_numeric($valorNumerico)) {
        return "<c r=\"{$ref}\"{$sAttr}><v>" . (0 + $valorNumerico) . "</v></c>";
    }

    $texto = xlsx_escape((string) $valor);
    return "<c r=\"{$ref}\"{$sAttr} t=\"inlineStr\"><is><t xml:space=\"preserve\">{$texto}</t></is></c>";
}

/**
 * Genera y envía al navegador un archivo .xlsx con una sola hoja.
 *
 * @param string $nombreArchivo   Nombre del archivo a descargar (con .xlsx)
 * @param string $hoja            Nombre de la hoja dentro del libro
 * @param array  $encabezados     Lista de títulos de columna
 * @param array  $filas           Lista de filas (cada una: lista de valores en el mismo orden que $encabezados)
 * @param array  $columnasTexto   Índices (0-based) de columnas que deben tratarse siempre como texto (ej: teléfonos)
 */
function descargar_xlsx(string $nombreArchivo, string $hoja, array $encabezados, array $filas, array $columnasTexto = []): void
{
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
        '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
        '</Types>'
    );

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '</Relationships>'
    );

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets><sheet name="' . xlsx_escape($hoja) . '" sheetId="1" r:id="rId1"/></sheets>' .
        '</workbook>'
    );

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
        '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
        '</Relationships>'
    );

    // Estilo 1 = encabezado en negrita, blanco, con fondo vino (mismo tono que el diseño del sistema).
    $zip->addFromString('xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>' .
        '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>' .
        '<fills count="3"><fill><patternFill patternType="none"/></fill>' .
        '<fill><patternFill patternType="gray125"/></fill>' .
        '<fill><patternFill patternType="solid"><fgColor rgb="FF7A2748"/><bgColor indexed="64"/></patternFill></fill></fills>' .
        '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>' .
        '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
        '<cellXfs count="2">' .
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' .
        '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>' .
        '</cellXfs>' .
        '</styleSheet>'
    );

    $totalCols = count($encabezados);
    $colsXml = '<cols>';
    for ($i = 0; $i < $totalCols; $i++) {
        $colsXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="20" customWidth="1"/>';
    }
    $colsXml .= '</cols>';

    $filasXml = '<row r="1">';
    foreach ($encabezados as $i => $texto) {
        $ref = xlsx_columna($i) . '1';
        $filasXml .= xlsx_celda($ref, $texto, true, 1);
    }
    $filasXml .= '</row>';

    foreach ($filas as $numFila => $fila) {
        $r = $numFila + 2;
        $filasXml .= '<row r="' . $r . '">';
        foreach (array_values($fila) as $i => $valor) {
            $ref = xlsx_columna($i) . $r;
            $esTexto = in_array($i, $columnasTexto, true);
            $filasXml .= xlsx_celda($ref, $valor, $esTexto);
        }
        $filasXml .= '</row>';
    }

    $zip->addFromString('xl/worksheets/sheet1.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        $colsXml .
        '<sheetData>' . $filasXml . '</sheetData>' .
        '</worksheet>'
    );

    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}
