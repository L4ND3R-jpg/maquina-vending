<?php
require_once '../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';
$conn   = getConnection();
$user   = $body['usuario'] ?? 'WEB_USER';

switch ($action) {

    // ── Reponer stock: suma unidades a T_STOCKMAQ ─────────────────────
    case 'reponer':
        $id_stock  = (int)($body['id_stockmaq'] ?? 0);
        $cantidad  = (int)($body['cantidad']    ?? 0);
        if (!$id_stock || $cantidad <= 0) jsonResponse(['error' => 'Datos inválidos'], 400);

        $sql = "UPDATE T_STOCKMAQ
                SET N_CANTIDAD     = N_CANTIDAD + :qty,
                    U_MODIFICACION = :usr,
                    F_MODIFICACION = SYSDATE
                WHERE ID_STOCKMAQ = :id";
        $stmt = oci_parse($conn, $sql);
        oci_bind_by_name($stmt, ':qty', $cantidad);
        oci_bind_by_name($stmt, ':usr', $user);
        oci_bind_by_name($stmt, ':id',  $id_stock);
        if (!oci_execute($stmt)) {
            oci_rollback($conn);
            jsonResponse(['error' => 'Error al reponer stock'], 500);
        }
        oci_commit($conn);
        jsonResponse(['ok' => true, 'mensaje' => "Stock actualizado (+$cantidad unidades)"]);
        break;

    // ── Reponer múltiples productos a la vez ──────────────────────────
    case 'reponer_lote':
        $items = $body['items'] ?? [];
        if (empty($items)) jsonResponse(['error' => 'Lista vacía'], 400);

        $sql = "UPDATE T_STOCKMAQ
                SET N_CANTIDAD     = N_CANTIDAD + :qty,
                    U_MODIFICACION = :usr,
                    F_MODIFICACION = SYSDATE
                WHERE ID_STOCKMAQ = :id";
        $stmt = oci_parse($conn, $sql);

        foreach ($items as $item) {
            $id  = (int)$item['id_stockmaq'];
            $qty = (int)$item['cantidad'];
            if (!$id || $qty <= 0) continue;
            oci_bind_by_name($stmt, ':qty', $qty);
            oci_bind_by_name($stmt, ':usr', $user);
            oci_bind_by_name($stmt, ':id',  $id);
            if (!oci_execute($stmt)) {
                oci_rollback($conn);
                jsonResponse(['error' => 'Error reponiendo lote'], 500);
            }
        }
        oci_commit($conn);
        jsonResponse(['ok' => true, 'mensaje' => 'Reposición en lote completada']);
        break;

    // ── Añadir producto nuevo a una máquina ───────────────────────────
    case 'añadir_producto':
        $id_maq  = (int)($body['id_maquina']  ?? 0);
        $id_prod = (int)($body['id_producto'] ?? 0);
        $cant    = (int)($body['cantidad']    ?? 0);
        if (!$id_maq || !$id_prod) jsonResponse(['error' => 'Datos incompletos'], 400);

        // Comprobar si ya existe (borrado lógico incluido)
        $check = oci_parse($conn,
            "SELECT ID_STOCKMAQ FROM T_STOCKMAQ
             WHERE MAQ_ID_MAQUINA = :maq AND PROD_ID_PRODUCTO = :prod");
        oci_bind_by_name($check, ':maq',  $id_maq);
        oci_bind_by_name($check, ':prod', $id_prod);
        oci_execute($check);
        $existing = oci_fetch_assoc($check);

        if ($existing) {
            // Reactivar y actualizar cantidad
            $upd = oci_parse($conn,
                "UPDATE T_STOCKMAQ SET N_CANTIDAD = :qty, L_BORRADO = NULL,
                 U_MODIFICACION = :usr, F_MODIFICACION = SYSDATE
                 WHERE ID_STOCKMAQ = :id");
            oci_bind_by_name($upd, ':qty', $cant);
            oci_bind_by_name($upd, ':usr', $user);
            $eid = $existing['ID_STOCKMAQ'];
            oci_bind_by_name($upd, ':id', $eid);
            if (!oci_execute($upd)) {
                oci_rollback($conn);
                jsonResponse(['error' => 'Error BD'], 500);
            }
        } else {
                        $ins = oci_parse($conn,
                                "INSERT INTO T_STOCKMAQ
                                 (ID_STOCKMAQ, MAQ_ID_MAQUINA, PROD_ID_PRODUCTO, N_CANTIDAD,
                                    U_CREACION, F_CREACION, U_MODIFICACION, F_MODIFICACION)
                                 SELECT (SELECT NVL(MAX(CASE
                                                                                    WHEN REGEXP_LIKE(TO_CHAR(ID_STOCKMAQ), '^[0-9]+$')
                                                                                    THEN TO_NUMBER(TO_CHAR(ID_STOCKMAQ))
                                                                                END), 0) + 1
                                                 FROM T_STOCKMAQ),
                                                :maq, :prod, :qty, :usr, SYSDATE, :usr, SYSDATE
                                 FROM dual");
            oci_bind_by_name($ins, ':maq',  $id_maq);
            oci_bind_by_name($ins, ':prod', $id_prod);
            oci_bind_by_name($ins, ':qty',  $cant);
            oci_bind_by_name($ins, ':usr',  $user);
            if (!oci_execute($ins)) {
                oci_rollback($conn);
                jsonResponse(['error' => 'Error BD'], 500);
            }
        }
        oci_commit($conn);
        jsonResponse(['ok' => true, 'mensaje' => 'Producto añadido a la máquina']);
        break;

    // ── Vender: resta unidades del stock ─────────────────────────────
    case 'vender':
        $id_stock = (int)($body['id_stockmaq'] ?? 0);
        $cantidad = (int)($body['cantidad']    ?? 1);
        if (!$id_stock || $cantidad <= 0) jsonResponse(['error' => 'Datos inválidos'], 400);

        $sql = "UPDATE T_STOCKMAQ
                SET N_CANTIDAD     = N_CANTIDAD - :qty,
                    U_MODIFICACION = :usr,
                    F_MODIFICACION = SYSDATE
                WHERE ID_STOCKMAQ = :id AND N_CANTIDAD >= :qty";
        $stmt = oci_parse($conn, $sql);
        oci_bind_by_name($stmt, ':qty', $cantidad);
        oci_bind_by_name($stmt, ':usr', $user);
        oci_bind_by_name($stmt, ':id',  $id_stock);
        if (!oci_execute($stmt)) {
            oci_rollback($conn);
            jsonResponse(['error' => 'Error de BD'], 500);
        }
        if (oci_num_rows($stmt) == 0) {
            oci_rollback($conn);
            jsonResponse(['error' => 'Stock insuficiente o producto no encontrado'], 400);
        }
                oci_commit($conn);

                $check = oci_parse($conn, "SELECT N_CANTIDAD FROM T_STOCKMAQ WHERE ID_STOCKMAQ = :id");
                oci_bind_by_name($check, ':id', $id_stock);
                oci_execute($check);
                $row = oci_fetch_assoc($check);
                $nuevo = $row ? $row['N_CANTIDAD'] : 0;

                // Insertar registro en HISTORICO_VENTAS (no bloquear la venta si falla)
                $hist_sql = "INSERT INTO HISTORICO_VENTAS (
                        ID, ID_STOCKMAQ, ID_PRODUCTO, ID_MAQUINA, C_PRODUCTO, D_DESCRIPCION,
                        CANTIDAD, PRECIO_UNITARIO, TOTAL, USER_ROLE, USER_NAME, SESSION_ID, SOURCE, VENTA_TS
                    )
                    SELECT HISTORICO_VENTAS_SEQ.NEXTVAL, s.ID_STOCKMAQ, s.PROD_ID_PRODUCTO, s.MAQ_ID_MAQUINA, p.C_PRODUCTO, p.D_DESCRIPCION, :qty_hist, NVL(s.I_PRECIO, p.I_PRECIO, 0), :qty_hist * NVL(NVL(s.I_PRECIO, p.I_PRECIO),0), :role, :usr, :sess, 'APP', SYSTIMESTAMP
                    FROM T_STOCKMAQ s LEFT JOIN T_PRODUCTO p ON p.ID_PRODUCTO = s.PROD_ID_PRODUCTO
                    WHERE s.ID_STOCKMAQ = :id_hist";

                $hist_stmt = @oci_parse($conn, $hist_sql);
                if ($hist_stmt) {
                        $role = $body['role'] ?? null;
                        $session_id = $body['session_id'] ?? null;
                        oci_bind_by_name($hist_stmt, ':qty_hist', $cantidad);
                        oci_bind_by_name($hist_stmt, ':role', $role);
                        oci_bind_by_name($hist_stmt, ':usr', $user);
                        oci_bind_by_name($hist_stmt, ':sess', $session_id);
                        oci_bind_by_name($hist_stmt, ':id_hist', $id_stock);
                        @oci_execute($hist_stmt);
                }

                jsonResponse(['ok' => true, 'mensaje' => "Venta registrada (-$cantidad uds.). Stock restante: $nuevo"]);
        break;

    // ── Quitar producto de máquina (borrado lógico) ───────────────────
    case 'quitar_producto':
        $id_stock = (int)($body['id_stockmaq'] ?? 0);
        if (!$id_stock) jsonResponse(['error' => 'ID requerido'], 400);
        $sql = "UPDATE T_STOCKMAQ
                SET L_BORRADO = 'S',
                    U_MODIFICACION = :usr,
                    F_MODIFICACION = SYSDATE
                WHERE ID_STOCKMAQ = :id";
        $stmt = oci_parse($conn, $sql);
        oci_bind_by_name($stmt, ':usr', $user);
        oci_bind_by_name($stmt, ':id',  $id_stock);
        if (!oci_execute($stmt)) {
            oci_rollback($conn);
            jsonResponse(['error' => 'Error de BD'], 500);
        }
        oci_commit($conn);
        jsonResponse(['ok' => true, 'mensaje' => 'Producto eliminado de la máquina']);
        break;

    // ── Crear nuevo producto en el catálogo general ───────────────────
    case 'crear_producto':
        $c_prod = trim($body['c_producto'] ?? '');
        $d_prod = trim($body['d_descripcion'] ?? '');
        $precio = (float)($body['precio'] ?? 0.0);

        if (empty($c_prod) || empty($d_prod) || $precio <= 0) {
            jsonResponse(['error' => 'Datos de producto inválidos'], 400);
        }

        $check = oci_parse($conn, "SELECT ID_PRODUCTO FROM T_PRODUCTO WHERE C_PRODUCTO = :c_prod AND (L_BORRADO IS NULL OR L_BORRADO <> 'S')");
        oci_bind_by_name($check, ':c_prod', $c_prod);
        if (!oci_execute($check)) {
            oci_rollback($conn);
            jsonResponse(['error' => 'Error comprobando duplicados'], 500);
        }
        if (oci_fetch_assoc($check)) {
            jsonResponse(['error' => 'Ya existe un producto con el mismo código'], 400);
        }

        $cols_stmt = oci_parse($conn, "SELECT COLUMN_NAME, DATA_TYPE FROM USER_TAB_COLUMNS WHERE TABLE_NAME = 'T_PRODUCTO'");
        if (!oci_execute($cols_stmt)) {
            oci_rollback($conn);
            jsonResponse(['error' => 'Error leyendo esquema de productos'], 500);
        }
        $columns = [];
        $columnTypes = [];
        while ($col = oci_fetch_assoc($cols_stmt)) {
            $colName = strtoupper($col['COLUMN_NAME']);
            $columns[] = $colName;
            $columnTypes[$colName] = strtoupper($col['DATA_TYPE'] ?? '');
        }

        $sizes_stmt = oci_parse($conn, "SELECT COLUMN_NAME, DATA_LENGTH FROM USER_TAB_COLUMNS WHERE TABLE_NAME = 'T_PRODUCTO' AND COLUMN_NAME IN ('C_PRODUCTO', 'D_DESCRIPCION')");
        if (!oci_execute($sizes_stmt)) {
            oci_rollback($conn);
            jsonResponse(['error' => 'Error leyendo límites de productos'], 500);
        }
        $limits = [];
        while ($sizeRow = oci_fetch_assoc($sizes_stmt)) {
            $limits[strtoupper($sizeRow['COLUMN_NAME'])] = (int)$sizeRow['DATA_LENGTH'];
        }

        $numeric_stmt = oci_parse($conn, "SELECT COLUMN_NAME, DATA_PRECISION, DATA_SCALE
                                          FROM USER_TAB_COLUMNS
                                          WHERE TABLE_NAME = 'T_PRODUCTO'
                                            AND COLUMN_NAME IN ('ID_PRODUCTO', 'I_PRECIO')");
        if (!oci_execute($numeric_stmt)) {
            oci_rollback($conn);
            jsonResponse(['error' => 'Error leyendo límites numéricos de productos'], 500);
        }
        $numericLimits = [];
        while ($numRow = oci_fetch_assoc($numeric_stmt)) {
            $numericLimits[strtoupper($numRow['COLUMN_NAME'])] = [
                'precision' => isset($numRow['DATA_PRECISION']) ? (int)$numRow['DATA_PRECISION'] : null,
                'scale' => isset($numRow['DATA_SCALE']) ? (int)$numRow['DATA_SCALE'] : null,
            ];
        }

        $codeLimit = $limits['C_PRODUCTO'] ?? 0;
        $descLimit = $limits['D_DESCRIPCION'] ?? 0;
        if ($codeLimit > 0 && strlen($c_prod) > $codeLimit) {
            jsonResponse(['error' => 'El código del producto es demasiado largo', 'detail' => 'Máximo permitido para C_PRODUCTO: ' . $codeLimit . ' caracteres'], 400);
        }
        if ($descLimit > 0 && strlen($d_prod) > $descLimit) {
            jsonResponse(['error' => 'La descripción del producto es demasiado larga', 'detail' => 'Máximo permitido para D_DESCRIPCION: ' . $descLimit . ' caracteres'], 400);
        }

        $priceMeta = $numericLimits['I_PRECIO'] ?? null;
        if ($priceMeta && isset($priceMeta['precision'], $priceMeta['scale']) && $priceMeta['precision'] !== null && $priceMeta['scale'] !== null) {
            $maxPrice = pow(10, $priceMeta['precision'] - $priceMeta['scale']) - pow(10, -$priceMeta['scale']);
            $roundedPrice = round($precio, $priceMeta['scale']);
            if ($roundedPrice <= 0 || $roundedPrice > $maxPrice) {
                jsonResponse([
                    'error' => 'El precio no cabe en la base de datos',
                    'detail' => 'I_PRECIO admite hasta ' . number_format($maxPrice, $priceMeta['scale'], '.', '')
                ], 400);
            }
            $precio = $roundedPrice;
        }

        $nextIdStmt = oci_parse($conn, "SELECT NVL(MAX(CASE
                                                       WHEN REGEXP_LIKE(TO_CHAR(ID_PRODUCTO), '^[0-9]+$')
                                                       THEN TO_NUMBER(TO_CHAR(ID_PRODUCTO))
                                                   END), 0) + 1 AS NEXT_ID
                                     FROM T_PRODUCTO");
        if (!oci_execute($nextIdStmt)) {
            oci_rollback($conn);
            jsonResponse(['error' => 'Error calculando identificador de producto'], 500);
        }
        $nextIdRow = oci_fetch_assoc($nextIdStmt);
        $nextId = isset($nextIdRow['NEXT_ID']) ? (int)$nextIdRow['NEXT_ID'] : 0;
        $idMeta = $numericLimits['ID_PRODUCTO'] ?? null;
        if ($idMeta && isset($idMeta['precision']) && $idMeta['precision'] !== null) {
            $maxId = (int)pow(10, $idMeta['precision']) - 1;
            if ($nextId > $maxId) {
                jsonResponse([
                    'error' => 'No hay espacio para más productos',
                    'detail' => 'ID_PRODUCTO supera la precisión permitida por la tabla'
                ], 400);
            }
        }

        $nextIdText = (string)$nextId;
        $priceText = number_format($precio, 2, '.', '');

                $insert_cols = ['ID_PRODUCTO', 'C_PRODUCTO', 'D_DESCRIPCION', 'I_PRECIO'];
        $insert_vals = ['TO_NUMBER(:next_id_txt)', ':code', ':description', "TO_NUMBER(:price_txt, '9999999990D99', 'NLS_NUMERIC_CHARACTERS=.,')"];

        $userIsText = function (?string $type): bool {
            return in_array($type, ['CHAR', 'NCHAR', 'VARCHAR2', 'NVARCHAR2', 'CLOB', 'NCLOB'], true);
        };

        if (in_array('U_CREACION', $columns, true)) {
            $insert_cols[] = 'U_CREACION';
            $insert_vals[] = $userIsText($columnTypes['U_CREACION'] ?? null) ? ':usr' : 'NULL';
        }
        if (in_array('F_CREACION', $columns, true)) {
            $insert_cols[] = 'F_CREACION';
            $insert_vals[] = 'SYSDATE';
        }
        if (in_array('U_MODIFICACION', $columns, true)) {
            $insert_cols[] = 'U_MODIFICACION';
            $insert_vals[] = $userIsText($columnTypes['U_MODIFICACION'] ?? null) ? ':usr' : 'NULL';
        }
        if (in_array('F_MODIFICACION', $columns, true)) {
            $insert_cols[] = 'F_MODIFICACION';
            $insert_vals[] = 'SYSDATE';
        }

        $sql = "INSERT INTO T_PRODUCTO (" . implode(', ', $insert_cols) . ")
            SELECT " . implode(', ', $insert_vals) . " FROM dual";
        $stmt = oci_parse($conn, $sql);
        oci_bind_by_name($stmt, ':next_id_txt', $nextIdText);
        oci_bind_by_name($stmt, ':code',  $c_prod);
        oci_bind_by_name($stmt, ':description',  $d_prod);
        oci_bind_by_name($stmt, ':price_txt', $priceText);
        if ((in_array('U_CREACION', $columns, true) && $userIsText($columnTypes['U_CREACION'] ?? null)) ||
            (in_array('U_MODIFICACION', $columns, true) && $userIsText($columnTypes['U_MODIFICACION'] ?? null))) {
            oci_bind_by_name($stmt, ':usr', $user);
        }

        if (!oci_execute($stmt)) {
            oci_rollback($conn);
            $e = oci_error($stmt);
            $detail = $e && isset($e['message']) ? $e['message'] : 'Error al crear producto';
            jsonResponse(['error' => 'Error al crear producto', 'detail' => $detail], 500);
        }
        oci_commit($conn);
        jsonResponse(['ok' => true, 'mensaje' => 'Producto creado con éxito en el catálogo']);
        break;

    // ── Vender múltiples productos de una cesta (compra múltiple) ─────
    case 'vender_lote':
        $items = $body['items'] ?? [];
        if (empty($items)) jsonResponse(['error' => 'Cesta vacía'], 400);

        $sql = "UPDATE T_STOCKMAQ
                SET N_CANTIDAD     = N_CANTIDAD - :qty,
                    U_MODIFICACION = :usr,
                    F_MODIFICACION = SYSDATE
                WHERE ID_STOCKMAQ = :id AND N_CANTIDAD >= :qty";
        $stmt = oci_parse($conn, $sql);

        foreach ($items as $item) {
            $id  = (int)$item['id_stockmaq'];
            $qty = (int)$item['cantidad'];
            if (!$id || $qty <= 0) continue;

            oci_bind_by_name($stmt, ':qty', $qty);
            oci_bind_by_name($stmt, ':usr', $user);
            oci_bind_by_name($stmt, ':id',  $id);
            if (!oci_execute($stmt)) {
                oci_rollback($conn);
                jsonResponse(['error' => 'Error de BD'], 500);
            }
            if (oci_num_rows($stmt) == 0) {
                oci_rollback($conn);
                jsonResponse(['error' => 'Stock insuficiente para procesar todo el lote'], 400);
            }

            // Insertar histórico por línea (no bloquear si falla)
            $hist_sql = "INSERT INTO HISTORICO_VENTAS (ID, ID_STOCKMAQ, ID_PRODUCTO, ID_MAQUINA, C_PRODUCTO, D_DESCRIPCION, CANTIDAD, PRECIO_UNITARIO, TOTAL, USER_ROLE, USER_NAME, SESSION_ID, SOURCE, VENTA_TS)\n                SELECT HISTORICO_VENTAS_SEQ.NEXTVAL, s.ID_STOCKMAQ, s.PROD_ID_PRODUCTO, s.MAQ_ID_MAQUINA, p.C_PRODUCTO, p.D_DESCRIPCION, :qty_line, NVL(s.I_PRECIO, p.I_PRECIO, 0), :qty_line * NVL(NVL(s.I_PRECIO, p.I_PRECIO),0), :role, :usr, :sess, 'APP', SYSTIMESTAMP\n                FROM T_STOCKMAQ s LEFT JOIN T_PRODUCTO p ON p.ID_PRODUCTO = s.PROD_ID_PRODUCTO\n                WHERE s.ID_STOCKMAQ = :id_line";
            $hist_stmt = @oci_parse($conn, $hist_sql);
            if ($hist_stmt) {
                $role = $body['role'] ?? null;
                $session_id = $body['session_id'] ?? null;
                oci_bind_by_name($hist_stmt, ':qty_line', $qty);
                oci_bind_by_name($hist_stmt, ':role', $role);
                oci_bind_by_name($hist_stmt, ':usr', $user);
                oci_bind_by_name($hist_stmt, ':sess', $session_id);
                oci_bind_by_name($hist_stmt, ':id_line', $id);
                @oci_execute($hist_stmt);
            }
        }
        oci_commit($conn);
        jsonResponse(['ok' => true, 'mensaje' => 'Compra procesada correctamente. ¡Disfrute su producto!']);
        break;

    default:
        jsonResponse(['error' => 'Acción no reconocida'], 400);
}

oci_close($conn);

