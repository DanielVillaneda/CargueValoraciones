<?php
declare(strict_types=1);

require_once __DIR__ . '/src/LectorValoraciones.php';

/**
 * Uso: php leer_valoraciones.php <plantilla.xlsx> [salida.json]
 * Sin salida, imprime el JSON por stdout.
 */
if ($argc < 2) {
    fwrite(STDERR, "Uso: php leer_valoraciones.php <plantilla.xlsx> [salida.json]\n");
    exit(2);
}

try {
    $json = LectorValoraciones::aJson(LectorValoraciones::leer($argv[1]));
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

if (isset($argv[2])) {
    file_put_contents($argv[2], $json);
    fwrite(STDERR, "JSON escrito en {$argv[2]}\n");
} else {
    echo $json, "\n";
}
