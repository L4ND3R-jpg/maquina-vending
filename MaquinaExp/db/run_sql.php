<?php
// run_sql.php
// Uso: php run_sql.php <ruta_al_script_sql>
// Ejecuta un script SQL/PLSQL en la BD usando getConnection() de config.php

if (php_sapi_name() !== 'cli') {
    echo "Este script debe ejecutarse desde la línea de comandos.\n";
    exit(1);
}

if ($argc < 2) {
    echo "Uso: php run_sql.php <ruta_al_script_sql>\n";
    exit(2);
}

$path = $argv[1];
if (!file_exists($path)) {
    echo "Fichero no encontrado: $path\n";
    exit(3);
}

require_once __DIR__ . '/../config.php';
$conn = getConnection();

$contents = file_get_contents($path);
$lines = preg_split('/\r?\n/', $contents);
$statements = [];
$buffer = '';
foreach ($lines as $line) {
    // Detectar slash solo en su propia línea como separador de bloques
    if (trim($line) === '/') {
        if (trim($buffer) !== '') {
            $statements[] = $buffer;
            $buffer = '';
        }
        continue;
    }
    $buffer .= $line . "\n";
}
if (trim($buffer) !== '') $statements[] = $buffer;

echo "Ejecutando " . count($statements) . " sentencias desde: $path\n";
$idx = 0;
$errors = [];
foreach ($statements as $stmt) {
    $idx++;
    $s = trim($stmt);
    if ($s === '') continue;

    // Remover el punto y coma final si está fuera de un bloque PL/SQL
    if (substr(trim($s), -1) === ';') {
        $s = rtrim(trim($s), ';');
    }

    echo "[{$idx}] Ejecutando...\n";
    $stid = @oci_parse($conn, $s);
    if (!$stid) {
        $err = oci_error($conn);
        $errors[] = [ 'idx'=>$idx, 'error'=> $err['message'] ?? 'oci_parse failed' ];
        echo "  Error parseando: " . ($err['message'] ?? 'unknown') . "\n";
        continue;
    }

    $ok = @oci_execute($stid);
    if (!$ok) {
        $err = oci_error($stid);
        $errors[] = [ 'idx'=>$idx, 'error'=> $err['message'] ?? 'oci_execute failed' ];
        echo "  Error ejecutando: " . ($err['message'] ?? 'unknown') . "\n";
        continue;
    }
    echo "  OK\n";
}

if ($errors) {
    echo "\nSe produjeron " . count($errors) . " errores:\n";
    foreach ($errors as $e) {
        echo "  Sentencia {$e['idx']}: {$e['error']}\n";
    }
    exit(4);
}

echo "\nEjecución completada sin errores.\n";
oci_close($conn);
return 0;
