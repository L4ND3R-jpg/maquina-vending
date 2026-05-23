<?php
// =============================================
//  CONFIGURACIÓN DE CONEXIÓN ORACLE
//  Edita estos valores con tus credenciales
// =============================================
define('DB_USER',     'MaquinaExp');
define('DB_PASS',     '123');
define('DB_HOST',     'localhost');
define('DB_PORT',     '1521');
define('DB_SERVICE',  'XEPDB1');  // o SID según tu instancia

define('LOW_STOCK_THRESHOLD', 5); // Unidades mínimas antes de avisar

function getConnection() {
    $dsn = DB_USER . '/' . DB_PASS . '@' . DB_HOST . ':' . DB_PORT . '/' . DB_SERVICE;
    $conn = oci_connect(DB_USER, DB_PASS, '//' . DB_HOST . ':' . DB_PORT . '/' . DB_SERVICE, 'AL32UTF8');
    if (!$conn) {
        http_response_code(500);
        die(json_encode(['error' => 'Error interno de conexión a la base de datos']));
    }
    return $conn;
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
