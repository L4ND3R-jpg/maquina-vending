<?php
require_once '../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

$action = $_GET['action'] ?? '';
$conn   = getConnection();

switch ($action) {

    // ── Lista de puntos de venta con sus máquinas ──────────────────────
    case 'puntos_venta':
        $sql = "SELECT p.ID_PUNTOVENTA, p.C_PUNTOVENTA, p.D_DESCRIPCION,
                       COUNT(m.ID_MAQUINA) AS N_MAQUINAS
                FROM T_PUNTOVENTA p
                LEFT JOIN T_MAQUINA m ON m.PTV_ID_PUNTOVENTA = p.ID_PUNTOVENTA
                    AND (m.L_BORRADO IS NULL OR m.L_BORRADO <> 'S')
                WHERE (p.L_BORRADO IS NULL OR p.L_BORRADO <> 'S')
                GROUP BY p.ID_PUNTOVENTA, p.C_PUNTOVENTA, p.D_DESCRIPCION
                ORDER BY p.C_PUNTOVENTA";
        $stmt = oci_parse($conn, $sql);
        if (!oci_execute($stmt)) jsonResponse(['error' => 'Error BD'], 500);
        $rows = [];
        while ($row = oci_fetch_assoc($stmt)) $rows[] = $row;
        jsonResponse($rows);
        break;

    // ── Stock de una máquina concreta ──────────────────────────────────
    case 'stock_maquina':
        $id = (int)($_GET['id'] ?? 0);
        $sql = "SELECT s.ID_STOCKMAQ, s.N_CANTIDAD,
                       p.ID_PRODUCTO, p.C_PRODUCTO, p.D_DESCRIPCION, p.I_PRECIO,
                       m.C_MAQUINA, m.D_DESCRIPCION AS D_MAQUINA,
                       pv.C_PUNTOVENTA
                FROM T_STOCKMAQ s
                JOIN T_PRODUCTO p  ON p.ID_PRODUCTO = s.PROD_ID_PRODUCTO
                JOIN T_MAQUINA  m  ON m.ID_MAQUINA  = s.MAQ_ID_MAQUINA
                JOIN T_PUNTOVENTA pv ON pv.ID_PUNTOVENTA = m.PTV_ID_PUNTOVENTA
                WHERE s.MAQ_ID_MAQUINA = :id
                  AND (s.L_BORRADO IS NULL OR s.L_BORRADO <> 'S')
                ORDER BY p.C_PRODUCTO";
        $stmt = oci_parse($conn, $sql);
        oci_bind_by_name($stmt, ':id', $id);
        if (!oci_execute($stmt)) jsonResponse(['error' => 'Error BD'], 500);
        $rows = [];
        while ($row = oci_fetch_assoc($stmt)) $rows[] = $row;
        jsonResponse($rows);
        break;

    // ── Todas las máquinas con info básica ────────────────────────────
    case 'maquinas':
        $ptv = (int)($_GET['ptv'] ?? 0);
        $where = $ptv ? "AND m.PTV_ID_PUNTOVENTA = :ptv" : "";
        $sql = "SELECT m.ID_MAQUINA, m.C_MAQUINA, m.D_DESCRIPCION,
                       pv.C_PUNTOVENTA, pv.ID_PUNTOVENTA,
                       NVL(SUM(s.N_CANTIDAD),0) AS TOTAL_UNIDADES,
                       COUNT(CASE WHEN s.N_CANTIDAD <= :threshold AND s.N_CANTIDAD > 0 THEN 1 END) AS PRODS_BAJOS,
                       COUNT(CASE WHEN s.N_CANTIDAD = 0 THEN 1 END) AS PRODS_AGOTADOS
                FROM T_MAQUINA m
                JOIN T_PUNTOVENTA pv ON pv.ID_PUNTOVENTA = m.PTV_ID_PUNTOVENTA
                LEFT JOIN T_STOCKMAQ s ON s.MAQ_ID_MAQUINA = m.ID_MAQUINA
                    AND (s.L_BORRADO IS NULL OR s.L_BORRADO <> 'S')
                WHERE (m.L_BORRADO IS NULL OR m.L_BORRADO <> 'S') $where
                GROUP BY m.ID_MAQUINA, m.C_MAQUINA, m.D_DESCRIPCION, pv.C_PUNTOVENTA, pv.ID_PUNTOVENTA
                ORDER BY pv.C_PUNTOVENTA, m.C_MAQUINA";
        $stmt = oci_parse($conn, $sql);
        $threshold = LOW_STOCK_THRESHOLD;
        oci_bind_by_name($stmt, ':threshold', $threshold);
        if ($ptv) oci_bind_by_name($stmt, ':ptv', $ptv);
        if (!oci_execute($stmt)) jsonResponse(['error' => 'Error BD'], 500);
        $rows = [];
        while ($row = oci_fetch_assoc($stmt)) $rows[] = $row;
        jsonResponse($rows);
        break;

    // ── Alertas de stock bajo global ──────────────────────────────────
    case 'alertas':
        $sql = "SELECT m.ID_MAQUINA, m.C_MAQUINA, pv.C_PUNTOVENTA,
                       p.ID_PRODUCTO, p.C_PRODUCTO, p.D_DESCRIPCION,
                       s.N_CANTIDAD, s.ID_STOCKMAQ
                FROM T_STOCKMAQ s
                JOIN T_PRODUCTO   p  ON p.ID_PRODUCTO = s.PROD_ID_PRODUCTO
                JOIN T_MAQUINA    m  ON m.ID_MAQUINA  = s.MAQ_ID_MAQUINA
                JOIN T_PUNTOVENTA pv ON pv.ID_PUNTOVENTA = m.PTV_ID_PUNTOVENTA
                WHERE s.N_CANTIDAD <= :threshold
                  AND (s.L_BORRADO IS NULL OR s.L_BORRADO <> 'S')
                  AND (m.L_BORRADO IS NULL OR m.L_BORRADO <> 'S')
                ORDER BY s.N_CANTIDAD ASC, pv.C_PUNTOVENTA";
        $stmt = oci_parse($conn, $sql);
        $threshold = LOW_STOCK_THRESHOLD;
        oci_bind_by_name($stmt, ':threshold', $threshold);
        if (!oci_execute($stmt)) jsonResponse(['error' => 'Error BD'], 500);
        $rows = [];
        while ($row = oci_fetch_assoc($stmt)) $rows[] = $row;
        jsonResponse($rows);
        break;

    // ── Catálogo de productos ─────────────────────────────────────────
    case 'productos':
        $sql = "SELECT ID_PRODUCTO, C_PRODUCTO, D_DESCRIPCION, I_PRECIO
                FROM T_PRODUCTO
                WHERE (L_BORRADO IS NULL OR L_BORRADO <> 'S')
                ORDER BY C_PRODUCTO";
        $stmt = oci_parse($conn, $sql);
        if (!oci_execute($stmt)) jsonResponse(['error' => 'Error BD'], 500);
        $rows = [];
        while ($row = oci_fetch_assoc($stmt)) $rows[] = $row;
        jsonResponse($rows);
        break;

    default:
        jsonResponse(['error' => 'Acción no reconocida'], 400);
}

oci_close($conn);
