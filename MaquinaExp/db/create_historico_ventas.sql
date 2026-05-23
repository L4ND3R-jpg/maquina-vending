-- Script: create_historico_ventas.sql
-- Crea la tabla de histórico de ventas, secuencia, índices y trigger
-- Diseñado para Oracle Database

-- 1) Secuencia para el ID de histórico
CREATE SEQUENCE HISTORICO_VENTAS_SEQ
  START WITH 1
  INCREMENT BY 1
  NOCACHE
  NOCYCLE;
/

-- 2) Tabla de histórico de ventas
CREATE TABLE HISTORICO_VENTAS (
  ID                 NUMBER PRIMARY KEY,
  ID_STOCKMAQ        NUMBER NOT NULL,
  ID_PRODUCTO        NUMBER,
  ID_MAQUINA         NUMBER,
  C_PRODUCTO         VARCHAR2(200), -- snapshot del código del producto (opcional)
  D_DESCRIPCION      VARCHAR2(1000), -- snapshot de la descripción
  CANTIDAD           NUMBER(10) NOT NULL,
  PRECIO_UNITARIO    NUMBER(14,4), -- mayor precisión posible para cálculos
  TOTAL              NUMBER(18,4),
  USER_ROLE          VARCHAR2(50),
  USER_NAME          VARCHAR2(200),
  SESSION_ID         VARCHAR2(128),
  SOURCE             VARCHAR2(50), -- 'TRIGGER' o 'APP'
  VENTA_TS           TIMESTAMP DEFAULT SYSTIMESTAMP
);
/

-- 3) Índices recomendados para consultas analíticas rápidas
CREATE INDEX IX_HV_VENTA_TS ON HISTORICO_VENTAS(VENTA_TS);
CREATE INDEX IX_HV_PRODUCTO ON HISTORICO_VENTAS(ID_PRODUCTO);
CREATE INDEX IX_HV_MAQUINA ON HISTORICO_VENTAS(ID_MAQUINA);
/

-- 4) Trigger: inserta en HISTORICO_VENTAS cuando N_CANTIDAD disminuye
--    Este trigger asume que la tabla T_STOCKMAQ contiene al menos:
--      - ID_STOCKMAQ
--      - MAQ_ID_MAQUINA
--      - PROD_ID_PRODUCTO
--      - N_CANTIDAD
--      - I_PRECIO (opcional, precio unitario en stock)
--    Si los nombres difieren en tu esquema, ajusta el trigger.

-- Trigger autónomo que registra ventas en HISTORICO_VENTAS sin referenciar columnas inexistentes
CREATE OR REPLACE TRIGGER TRG_T_STOCKMAQ_HISTORICO
AFTER UPDATE OF N_CANTIDAD ON T_STOCKMAQ
FOR EACH ROW
DECLARE
  PRAGMA AUTONOMOUS_TRANSACTION;
  v_sold    NUMBER := NVL(OLD.N_CANTIDAD,0) - NVL(NEW.N_CANTIDAD,0);
  v_price   NUMBER := 0;
  v_prod_id NUMBER := NULL;
  v_maq_id  NUMBER := NULL;
  v_total   NUMBER := 0;
  v_code    VARCHAR2(200);
  v_desc    VARCHAR2(1000);
BEGIN
  IF v_sold <= 0 THEN
    RETURN;
  END IF;

  -- Tomar identificadores seguros de la fila nueva
  v_prod_id := :NEW.PROD_ID_PRODUCTO;
  v_maq_id  := :NEW.MAQ_ID_MAQUINA;

  -- Intentar obtener precio y descripciones desde T_PRODUCTO (si existe)
  IF v_prod_id IS NOT NULL THEN
    BEGIN
      SELECT NVL(I_PRECIO,0), C_PRODUCTO, D_DESCRIPCION
        INTO v_price, v_code, v_desc
        FROM T_PRODUCTO p
       WHERE p.ID_PRODUCTO = v_prod_id;
    EXCEPTION
      WHEN NO_DATA_FOUND THEN
        v_price := 0;
        v_code  := NULL;
        v_desc  := NULL;
      WHEN OTHERS THEN
        v_price := 0;
        v_code  := NULL;
        v_desc  := NULL;
    END;
  END IF;

  v_total := v_sold * NVL(v_price,0);

  BEGIN
    INSERT INTO HISTORICO_VENTAS (
      ID, ID_STOCKMAQ, ID_PRODUCTO, ID_MAQUINA, C_PRODUCTO, D_DESCRIPCION,
      CANTIDAD, PRECIO_UNITARIO, TOTAL, SOURCE, VENTA_TS
    ) VALUES (
      HISTORICO_VENTAS_SEQ.NEXTVAL,
      :NEW.ID_STOCKMAQ,
      v_prod_id,
      v_maq_id,
      v_code,
      v_desc,
      v_sold,
      v_price,
      v_total,
      'TRIGGER',
      SYSTIMESTAMP
    );
    COMMIT; -- autonomously commit the logging insert so it won't rollback the caller
  EXCEPTION
    WHEN OTHERS THEN
      NULL; -- ignore logging failures to avoid affecting main transaction
  END;
END;
/

-- 5) Sugerencias operativas (no ejecutables en el script):
-- - Si el volumen de ventas es alto, considera particionar HISTORICO_VENTAS por rango de fecha (ej. por mes).
-- - Implementa una política de purga/archivado (DBMS_SCHEDULER o jobs externos) para mover datos antiguos.
-- - Si prefieres control desde la app (recomendado para incluir contexto de sesión/usuario), realiza la inserción desde la aplicación al procesar la venta.
-- - Revisa permisos: el usuario de la aplicación debe tener permisos INSERT sobre HISTORICO_VENTAS si optas por insertar desde la app.

-- 6) Ejemplo de INDEX adicional para reportes por fecha+producto
CREATE INDEX IX_HV_VENTA_TS_PROD ON HISTORICO_VENTAS(VENTA_TS, ID_PRODUCTO);
/

-- Fin del script
