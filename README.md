# Lector de cargue masivo de valoraciones

Lee la plantilla Excel (`.xlsx`) de valoraciones de un grado y grupo y la convierte a JSON, para que CSM Educativo lo consuma después.

- PHP 8.1 o superior (probado con 8.5).
- Extensiones: `zip`, `SimpleXML`, `mbstring`, `json`. No requiere Composer ni librerías externas.

## Estructura

```
leer_valoraciones.php        Script de línea de comandos
src/LectorValoraciones.php   Lógica de la plantilla → arreglo/JSON
src/LectorXlsx.php           Lector mínimo de .xlsx (primera hoja)
salida_ejemplo.json          Ejemplo de salida
```

## Uso

### Línea de comandos

```bash
php leer_valoraciones.php plantilla.xlsx salida.json
```

Sin el segundo argumento imprime el JSON por pantalla. Códigos de salida: `0` bien, `1` error al leer el archivo, `2` falta el argumento.

### Desde PHP

```php
require_once __DIR__ . '/src/LectorValoraciones.php';

$datos = LectorValoraciones::leer('plantilla.xlsx');   // arreglo
$json  = LectorValoraciones::aJson($datos);            // string JSON (UTF-8)

if (!$datos['valido']) {
    // revisar $datos['errores'] antes de guardar nada
}
```

`leer()` lanza `RuntimeException` si el archivo no existe, no es un `.xlsx` válido o no tiene la columna `ESTUDIANTE`.

## Formato de la plantilla

Se lee solo la **primera hoja**.

| Zona | Qué se espera |
|---|---|
| Encabezado (sobre la tabla) | `SEDE:` y `GRADO:`; el valor va en la celda siguiente. El grado se escribe `GRADO - GRUPO` (ej. `SEGUNDO - 01`). La `FECHA:` se ignora. |
| Fila de títulos | Debe contener `ESTUDIANTE`. |
| Columna de identificación | Título `IDENTIFICACION`, `DOCUMENTO`, `ID`, `NUI`, `CEDULA` (y variantes). Debe ir con formato **texto** si hay ceros a la izquierda. |
| Columnas de asignaturas | Cualquier título que no sea de control ni calculado (incluye `COMPORTAMIENTO`). |
| Códigos de asignatura | En la fila **inmediatamente debajo** de los títulos de las asignaturas. Esas celdas no pueden estar combinadas con el título. |
| Columna `ESTADO` / `ESTADO FINAL` | Opcional (aprobó/reprobó el año). |
| Columna `N°` | Opcional; da el `num_lista`. |
| Filas de estudiantes | Una por estudiante, debajo de los títulos. |

### Columnas ignoradas

`PROMEDIO GENERAL`, `PROMEDIO`, `AREAS REPROBADAS` y `PUESTO` no se leen: el sistema las recalcula. `PERIODO` se lee solo como dato informativo.

## Salida (JSON)

```json
{
  "valido": true,
  "sede": "CENTRAL URBANA",
  "grado": "SEGUNDO",
  "grupo": "01",
  "grado_texto": "SEGUNDO - 01",
  "fecha_reporte": "13/11/2026 14:05",
  "periodo": "DF",
  "areas": [
    { "codigo_asignatura": "ASG01", "asignatura": "ARTISTICA" }
  ],
  "total_estudiantes": 27,
  "estudiantes": [
    {
      "num_lista": 1,
      "identificacion": null,
      "nombre": "ALVAREZ DUARTE MARIA ISABELLA",
      "notas": [
        { "codigo_asignatura": "ASG01", "asignatura": "ARTISTICA", "nota": 10, "escala": "cuantitativa" }
      ],
      "periodo": "DF",
      "estado": null
    }
  ],
  "errores": [],
  "avisos": []
}
```

| Campo | Detalle |
|---|---|
| `valido` | `false` si hay algún elemento en `errores`. Los `avisos` no lo afectan. |
| `grado`, `grupo` | Salen de separar `grado_texto` por el guion. |
| `fecha_reporte` | Fecha y hora **de ejecución** de la lectura (hora de Colombia, `dd/mm/aaaa hh:mm`), no la del Excel. |
| `periodo` (nivel superior) | Solo aparece si todos los estudiantes tienen el mismo periodo; si no, `null`. |
| `num_lista` | Temporal. Valor de la columna `N°`; si no existe o no es entero, el orden de aparición. |
| `identificacion` | Texto sin decimales. `null` si la plantilla no tiene la columna. |
| `nombre` | Nombre completo, sin separar; se quitan espacios sobrantes. |
| `codigo_asignatura` | Código de la fila bajo el título. `null` si la plantilla no trae códigos. |
| `estado` | Valor de la columna `ESTADO`, en mayúsculas. `null` si no hay columna o la celda está vacía. No se valida su contenido. |

### Notas

- **Cuantitativa:** número de `0.0` a `10.0` (acepta coma decimal). Fuera de rango es un error. `"escala": "cuantitativa"`.
- **Cualitativa:** palabra completa (ej. `SOBRESALIENTE`), en mayúsculas. No hay lista de valores permitidos; si pasa de 13 caracteres se genera un aviso, no un error. `"escala": "cualitativa"`.
- **Celda vacía:** `nota` y `escala` en `null`, sin error.

## Errores y avisos

Los **errores** (`errores`, con `fila` del Excel y `mensaje`) hacen `valido: false`:

- Falta la sede o el grado en el encabezado.
- No se encontraron columnas de asignaturas o estudiantes.
- Falta el código de una asignatura (cuando la plantilla trae códigos), o hay códigos repetidos.
- Falta la identificación de un estudiante (cuando existe la columna).
- Estudiante repetido (por identificación, o por nombre si no hay columna de identificación).
- Nota cuantitativa fuera de 0–10.

Los **avisos** (`avisos`) no bloquean la carga:

- La plantilla no tiene columna de identificación.
- La plantilla no trae códigos de asignatura.
- No se pudo separar grado y grupo.
- Nota cualitativa de más de 13 caracteres.
