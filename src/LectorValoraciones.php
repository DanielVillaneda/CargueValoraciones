<?php
declare(strict_types=1);

require_once __DIR__ . '/LectorXlsx.php';

/**
 * Convierte la plantilla "Cargue masivo de valoraciones" en un arreglo listo para json_encode.
 *
 * Plantilla esperada:
 *  - Encabezado (filas sobre la tabla): "SEDE:" y "GRADO:" (la "FECHA:" se ignora), con el valor
 *    en la celda siguiente a la etiqueta. El grado viene como "SEGUNDO - 01" (grado - grupo).
 *  - Tabla: fila de títulos con "ESTUDIANTE"; luego una fila por estudiante.
 *  - Columnas de áreas = cualquier título que no sea de control ni calculado.
 *  - Identificación del estudiante: columna con título IDENTIFICACION / DOCUMENTO / ID / etc.
 *  - Código de cada asignatura: en la fila inmediatamente debajo de los títulos de áreas.
 */
final class LectorValoraciones
{
    private const NOTA_MIN = 0.0;
    private const NOTA_MAX = 10.0;
    /** Longitud esperada máxima de una nota cualitativa ("SOBRESALIENTE"); solo genera aviso, no error. */
    private const CUALITATIVA_LONGITUD_AVISO = 13;

    /** Columnas que se ignoran (control o calculadas por el sistema). */
    private const IGNORADAS = ['N°', 'N', 'NO', 'NUM', 'PERIODO', 'PROMEDIO GENERAL', 'PROMEDIO',
        'AREAS REPROBADAS', 'PUESTO'];

    /** Títulos aceptados para la columna de identificación del estudiante. */
    private const TITULOS_IDENTIFICACION = ['IDENTIFICACION', 'IDENTIFICACION ESTUDIANTE', 'DOCUMENTO',
        'NUMERO DOCUMENTO', 'N DOCUMENTO', 'N° DOCUMENTO', 'NRO DOCUMENTO', 'NUMERO DE DOCUMENTO',
        'ID', 'ID ESTUDIANTE', 'NUI', 'CEDULA', 'CODIGO ESTUDIANTE', 'CODIGO'];

    public static function leer(string $ruta): array
    {
        $filas = LectorXlsx::leerPrimeraHoja($ruta);
        $errores = [];
        $avisos = [];

        $filaTitulos = self::buscarFilaTitulos($filas);
        if ($filaTitulos === null) {
            throw new RuntimeException('No se encontró la columna "ESTUDIANTE" en la plantilla.');
        }

        $meta = self::leerEncabezado($filas, $filaTitulos);
        foreach (['sede' => 'SEDE', 'grado_texto' => 'GRADO'] as $k => $etq) {
            if (($meta[$k] ?? '') === '') {
                $errores[] = ['fila' => null, 'mensaje' => "Falta el dato $etq en el encabezado."];
            }
        }
        [$grado, $grupo] = self::partirGrado($meta['grado_texto'] ?? '');
        if ($grupo === null && ($meta['grado_texto'] ?? '') !== '') {
            $avisos[] = 'No se pudo separar grado y grupo (se esperaba "GRADO - GRUPO").';
        }

        // Mapa de columnas
        $colEstudiante = null;
        $colIdentificacion = null;
        $colNumLista = null;
        $colEstado = null;
        $colPeriodo = null;
        $areas = []; // col => nombre
        foreach ($filas[$filaTitulos] as $col => $titulo) {
            $nombre = self::normalizarTitulo((string)$titulo);
            if ($nombre === 'ESTUDIANTE') {
                $colEstudiante = $col;
            } elseif (in_array(self::sinTildes($nombre), self::TITULOS_IDENTIFICACION, true)) {
                $colIdentificacion = $col;
            } elseif (in_array($nombre, ['N°', 'N', 'NO', 'NUM', 'NRO', 'NUMERO'], true)) {
                $colNumLista = $col;
            } elseif (in_array(self::sinTildes($nombre), ['ESTADO', 'ESTADO FINAL'], true)) {
                $colEstado = $col;
            } elseif ($nombre === 'PERIODO') {
                $colPeriodo = $col;
            } elseif (!in_array($nombre, self::IGNORADAS, true)) {
                $areas[$col] = $nombre;
            }
        }
        if (!$areas) {
            $errores[] = ['fila' => $filaTitulos, 'mensaje' => 'No se encontraron columnas de áreas.'];
        }

        if ($colIdentificacion === null) {
            $avisos[] = 'La plantilla no tiene columna de identificación del estudiante (IDENTIFICACION / DOCUMENTO / ID).';
        }

        // Códigos de asignatura: fila debajo de los títulos (o, si no, la de encima)
        $codigos = self::leerCodigosAsignatura($filas, $filaTitulos, $areas, $colEstudiante);
        if (!array_filter($codigos)) {
            $avisos[] = 'La plantilla no trae códigos de asignatura (se esperan en la fila debajo de los títulos de las áreas).';
        } else {
            foreach ($areas as $col => $area) {
                if (($codigos[$col] ?? '') === '') {
                    $errores[] = ['fila' => null, 'mensaje' => "Falta el código de la asignatura $area."];
                }
            }
        }
        $codigosVistos = array_filter($codigos);
        if (count($codigosVistos) !== count(array_unique($codigosVistos))) {
            $errores[] = ['fila' => null, 'mensaje' => 'Hay códigos de asignatura repetidos.'];
        }

        // Estudiantes
        $estudiantes = [];
        $vistos = [];
        foreach ($filas as $nFila => $celdas) {
            if ($nFila <= $filaTitulos || !isset($celdas[$colEstudiante])) {
                continue;
            }
            $nombre = self::limpiarNombre((string)$celdas[$colEstudiante]);
            if ($nombre === '') {
                continue;
            }
            $identificacion = null;
            if ($colIdentificacion !== null) {
                $identificacion = self::textoCelda($celdas[$colIdentificacion] ?? null);
                if ($identificacion === '') {
                    $identificacion = null;
                    $errores[] = ['fila' => $nFila, 'mensaje' => "Falta la identificación de $nombre."];
                }
            }

            // Duplicados: por identificación si existe; si no, por nombre
            $clave = self::clave($nombre);
            $claveDup = $identificacion ?? $clave;
            if (isset($vistos[$claveDup])) {
                $errores[] = ['fila' => $nFila, 'mensaje' => "Estudiante repetido: $nombre (fila {$vistos[$claveDup]})."];
            }
            $vistos[$claveDup] = $nFila;

            $notas = [];
            foreach ($areas as $col => $area) {
                [$nota, $escala] = self::leerNota($celdas[$col] ?? null, $nFila, $area, $errores, $avisos);
                $notas[] = [
                    'codigo_asignatura' => ($codigos[$col] ?? '') !== '' ? $codigos[$col] : null,
                    'asignatura' => $area,
                    'nota' => $nota,
                    'escala' => $escala,
                ];
            }
            // num_lista (temporal): la columna N° de la plantilla; si no existe o no es entera, el orden de aparición.
            $numLista = $colNumLista !== null ? self::textoCelda($celdas[$colNumLista] ?? null) : '';
            $numLista = ctype_digit($numLista) ? (int)$numLista : count($estudiantes) + 1;

            // estado (aprobó/reprobó el año): opcional; null si no hay columna o la celda está vacía.
            $estado = $colEstado !== null ? self::normalizarTitulo(self::textoCelda($celdas[$colEstado] ?? null)) : '';

            $est = [
                'num_lista' => $numLista,
                'identificacion' => $identificacion,
                'nombre' => $nombre,
                'notas' => $notas,
                'estado' => $estado !== '' ? $estado : null,
            ];
            if ($colPeriodo !== null && isset($celdas[$colPeriodo])) {
                $est['periodo'] = trim((string)$celdas[$colPeriodo]);
            }
            $estudiantes[] = $est;
        }
        if (!$estudiantes) {
            $errores[] = ['fila' => null, 'mensaje' => 'No se encontraron estudiantes.'];
        }

        $periodos = array_values(array_unique(array_filter(array_column($estudiantes, 'periodo'))));

        return [
            'valido' => count($errores) === 0,
            'sede' => $meta['sede'] ?? null,
            'grado' => $grado,
            'grupo' => $grupo,
            'grado_texto' => $meta['grado_texto'] ?? null,
            // Fecha de ejecución de la lectura (no se lee del Excel), hora de Colombia.
            'fecha_reporte' => (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('d/m/Y H:i'),
            'periodo' => count($periodos) === 1 ? $periodos[0] : null,
            'areas' => array_map(
                fn($col) => ['codigo_asignatura' => ($codigos[$col] ?? '') !== '' ? $codigos[$col] : null, 'asignatura' => $areas[$col]],
                array_keys($areas)
            ),
            'total_estudiantes' => count($estudiantes),
            'estudiantes' => $estudiantes,
            'errores' => $errores,
            'avisos' => $avisos,
        ];
    }

    public static function aJson(array $datos): string
    {
        return json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Lee los códigos de asignatura de la fila inmediatamente debajo de los títulos.
     * @return array<int, string> col => código ('' si no hay)
     */
    private static function leerCodigosAsignatura(array $filas, int $filaTitulos, array $areas, int $colEstudiante): array
    {
        foreach ([$filaTitulos + 1] as $n) {
            $celdas = $filas[$n] ?? null;
            // La fila de códigos no puede ser una fila de estudiante
            if ($celdas === null || isset($celdas[$colEstudiante])) {
                continue;
            }
            $codigos = [];
            foreach ($areas as $col => $_) {
                $codigos[$col] = self::textoCelda($celdas[$col] ?? null);
            }
            if (array_filter($codigos)) {
                return $codigos;
            }
        }
        return [];
    }

    /** Texto de una celda; los números enteros se devuelven sin decimales ni notación científica. */
    private static function textoCelda(mixed $valor): string
    {
        if ($valor === null) {
            return '';
        }
        if (is_float($valor) && floor($valor) === $valor && abs($valor) < 1e15) {
            return sprintf('%.0f', $valor);
        }
        return trim((string)$valor);
    }

    private static function sinTildes(string $s): string
    {
        return strtr($s, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);
    }

    private static function buscarFilaTitulos(array $filas): ?int
    {
        foreach ($filas as $n => $celdas) {
            foreach ($celdas as $v) {
                if (self::normalizarTitulo((string)$v) === 'ESTUDIANTE') {
                    return $n;
                }
            }
        }
        return null;
    }

    /** Busca etiquetas "SEDE:", "GRADO:", "FECHA:" sobre la tabla; el valor es la siguiente celda con contenido. */
    private static function leerEncabezado(array $filas, int $filaTitulos): array
    {
        $etiquetas = ['SEDE' => 'sede', 'GRADO' => 'grado_texto'];
        $meta = [];
        foreach ($filas as $n => $celdas) {
            if ($n >= $filaTitulos) {
                break;
            }
            ksort($celdas);
            $cols = array_keys($celdas);
            foreach ($cols as $i => $col) {
                $crudo = trim((string)$celdas[$col]);
                $txt = rtrim(self::normalizarTitulo($crudo), ': ');
                if (isset($etiquetas[$txt]) && str_ends_with($crudo, ':')) {
                    $sig = $cols[$i + 1] ?? null;
                    if ($sig !== null) {
                        $meta[$etiquetas[$txt]] = self::limpiarNombre((string)$celdas[$sig]);
                    }
                }
            }
        }
        return $meta;
    }

    /** "SEGUNDO - 01" => ["SEGUNDO", "01"] */
    private static function partirGrado(string $texto): array
    {
        if (preg_match('/^(.+?)\s*[-–]\s*(\S+)$/u', trim($texto), $m)) {
            return [trim($m[1]), $m[2]];
        }
        return [$texto !== '' ? $texto : null, null];
    }

    /**
     * La nota puede ser cuantitativa (número, validado entre NOTA_MIN y NOTA_MAX) o
     * cualitativa (texto, p. ej. "S", "ALTO"; se normaliza a mayúsculas sin validar el catálogo).
     * @return array{0: float|string|null, 1: ?string} [nota, escala]
     */
    private static function leerNota(mixed $valor, int $fila, string $area, array &$errores, array &$avisos): array
    {
        if ($valor === null || $valor === '') {
            return [null, null];
        }
        if (is_string($valor)) {
            $valor = trim($valor);
            if ($valor === '') {
                return [null, null];
            }
            $comoNumero = str_replace(',', '.', $valor);
            if (!is_numeric($comoNumero)) {
                $texto = self::normalizarTitulo($valor);
                if (mb_strlen($texto, 'UTF-8') > self::CUALITATIVA_LONGITUD_AVISO) {
                    $avisos[] = "Fila $fila, $area: la nota cualitativa \"$texto\" es más larga de lo esperado (máx. "
                        . self::CUALITATIVA_LONGITUD_AVISO . " caracteres, p. ej. SOBRESALIENTE).";
                }
                return [$texto, 'cualitativa'];
            }
            $valor = $comoNumero;
        }
        $n = round((float)$valor, 2);
        if ($n < self::NOTA_MIN || $n > self::NOTA_MAX) {
            $errores[] = ['fila' => $fila, 'mensaje' => "Nota fuera de rango (" . self::NOTA_MIN . "-" . self::NOTA_MAX . ") en $area: $n."];
            return [null, null];
        }
        return [$n, 'cuantitativa'];
    }

    private static function limpiarNombre(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    private static function normalizarTitulo(string $s): string
    {
        return mb_strtoupper(self::limpiarNombre($s), 'UTF-8');
    }

    /** Clave para emparejar estudiantes: mayúsculas, sin tildes ni espacios extra (la Ñ se conserva). */
    private static function clave(string $nombre): string
    {
        $t = mb_strtoupper($nombre, 'UTF-8');
        return strtr($t, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U']);
    }
}
