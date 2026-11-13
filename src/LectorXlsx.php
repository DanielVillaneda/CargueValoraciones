<?php
declare(strict_types=1);

/**
 * Lector mínimo de .xlsx (sin dependencias, solo ext-zip y ext-simplexml).
 * Devuelve las celdas de la primera hoja como [fila => [columna(int, base 1) => valor]].
 */
final class LectorXlsx
{
    /** @return array<int, array<int, string|float|null>> */
    public static function leerPrimeraHoja(string $ruta): array
    {
        if (!is_file($ruta)) {
            throw new InvalidArgumentException("No existe el archivo: $ruta");
        }
        $zip = new ZipArchive();
        if ($zip->open($ruta) !== true) {
            throw new RuntimeException('El archivo no es un .xlsx válido.');
        }

        try {
            $compartidas = self::cadenasCompartidas($zip);
            $rutaHoja = self::rutaPrimeraHoja($zip);
            $xml = $zip->getFromName($rutaHoja);
            if ($xml === false) {
                throw new RuntimeException("No se encontró la hoja $rutaHoja.");
            }
        } finally {
            $zip->close();
        }

        $hoja = simplexml_load_string($xml);
        $filas = [];
        foreach ($hoja->sheetData->row ?? [] as $row) {
            foreach ($row->c as $c) {
                $valor = self::valorCelda($c, $compartidas);
                if ($valor === null || $valor === '') {
                    continue;
                }
                [$col, $fila] = self::coordenada((string)$c['r']);
                $filas[$fila][$col] = $valor;
            }
        }
        ksort($filas);
        return $filas;
    }

    private static function valorCelda(SimpleXMLElement $c, array $compartidas): string|float|null
    {
        $tipo = (string)$c['t'];
        if ($tipo === 'inlineStr') {
            return self::textoRico($c->is);
        }
        if (!isset($c->v)) {
            return null;
        }
        $v = (string)$c->v;
        return match ($tipo) {
            's' => $compartidas[(int)$v] ?? null,
            'str', 'e' => $v,
            'b' => $v === '1' ? 'TRUE' : 'FALSE',
            default => is_numeric($v) ? (float)$v : $v,
        };
    }

    /** @return string[] */
    private static function cadenasCompartidas(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $out = [];
        foreach (simplexml_load_string($xml)->si as $si) {
            $out[] = self::textoRico($si);
        }
        return $out;
    }

    private static function textoRico(SimpleXMLElement $nodo): string
    {
        if (isset($nodo->t)) {
            return (string)$nodo->t;
        }
        $txt = '';
        foreach ($nodo->r ?? [] as $r) {
            $txt .= (string)$r->t;
        }
        return $txt;
    }

    private static function rutaPrimeraHoja(ZipArchive $zip): string
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb === false || $rels === false) {
            return 'xl/worksheets/sheet1.xml';
        }
        $wbXml = simplexml_load_string($wb);
        $primera = $wbXml->sheets->sheet[0] ?? null;
        if ($primera === null) {
            throw new RuntimeException('El libro no tiene hojas.');
        }
        $rid = (string)$primera->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        foreach (simplexml_load_string($rels)->Relationship as $rel) {
            if ((string)$rel['Id'] === $rid) {
                $destino = ltrim((string)$rel['Target'], '/');
                return str_starts_with($destino, 'xl/') ? $destino : 'xl/' . $destino;
            }
        }
        return 'xl/worksheets/sheet1.xml';
    }

    /** "AB12" => [28, 12] */
    private static function coordenada(string $ref): array
    {
        preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
        $col = 0;
        foreach (str_split($m[1]) as $ch) {
            $col = $col * 26 + (ord($ch) - 64);
        }
        return [$col, (int)$m[2]];
    }
}
