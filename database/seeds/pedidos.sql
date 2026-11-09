-- =============================================================================
-- KALMA SNKRS · Datos semilla 3/3: pedidos
--
-- compose.yaml lo monta como /docker-entrypoint-initdb.d/04_pedidos.sql
--
-- 24 pedidos ficticios en todos los estados, 6 de ellos personalizados.
-- A mano solo se escriben los datos de negocio (quién, qué, cuándo y en qué
-- estado está). Todo lo demás se CALCULA para que sea coherente:
--   * precio aplicado  -> precio del producto en el catálogo
--   * total del pedido -> suma de los importes de sus líneas
--   * historial        -> recorrido de estados hasta el estado actual
--   * stock            -> stock inicial - ventas + cancelaciones
-- Al final, un bloque de comprobaciones aborta la carga si algo no cuadra.
--
-- Pedidos preparados para las demos de la defensa:
--   SF-2026-000021 (cliente@kalma.test, pendiente) -> la clienta lo puede cancelar
--   SF-2026-000013 (cliente@kalma.test, enviado)   -> ya no se puede cancelar (409)
--   SF-2026-000022 (marcos.gil, pendiente)         -> cliente@kalma.test NO puede tocarlo (IDOR)
-- =============================================================================

BEGIN;

-- -----------------------------------------------------------------------------
-- 1. Datos de partida (tablas temporales: desaparecen al hacer COMMIT)
-- -----------------------------------------------------------------------------

-- Pedidos. En los cancelados se indica desde qué estado, quién y por qué.
CREATE TEMP TABLE semilla_pedidos (
    codigo          VARCHAR(20)  PRIMARY KEY,
    email_cliente   VARCHAR(150) NOT NULL,
    estado          VARCHAR(20)  NOT NULL,
    fecha           TIMESTAMPTZ  NOT NULL,
    cancelado_desde VARCHAR(20),
    cancelado_por   VARCHAR(10),
    motivo          VARCHAR(255),
    notas           VARCHAR(500)
) ON COMMIT DROP;

INSERT INTO semilla_pedidos VALUES
    ('SF-2026-000001', 'paula.romero@correo.test',     'entregado',   '2026-07-03 11:20+02', NULL, NULL, NULL, NULL),
    ('SF-2026-000002', 'cd.lasencinas@correo.test',    'entregado',   '2026-07-08 10:05+02', NULL, NULL, NULL, 'Entregar en la secretaría del club, de 9:00 a 14:00'),
    ('SF-2026-000003', 'javier.ortega@correo.test',    'entregado',   '2026-07-15 18:40+02', NULL, NULL, NULL, NULL),
    ('SF-2026-000004', 'cliente@kalma.test',           'entregado',   '2026-07-22 20:10+02', NULL, NULL, NULL, 'Es un regalo: no incluir la factura en la caja'),
    ('SF-2026-000005', 'irene.castro@correo.test',     'entregado',   '2026-08-04 09:30+02', NULL, NULL, NULL, NULL),
    ('SF-2026-000006', 'daniel.vidal@correo.test',     'cancelado',   '2026-08-12 13:15+02', 'pendiente',  'cliente', 'Cancelado por el cliente: se equivocó de talla', NULL),
    ('SF-2026-000007', 'urbanrunners.sur@correo.test', 'entregado',   '2026-08-20 17:00+02', NULL, NULL, NULL, 'Equipación para la temporada 2026/27'),
    ('SF-2026-000008', 'sara.medina@correo.test',      'entregado',   '2026-09-02 12:45+02', NULL, NULL, NULL, NULL),
    ('SF-2026-000009', 'hugo.serrano@correo.test',     'cancelado',   '2026-09-10 19:20+02', 'confirmado', 'admin',   'Cancelado por administración: el banco rechazó el pago', NULL),
    ('SF-2026-000010', 'marcos.gil@correo.test',       'entregado',   '2026-09-18 16:30+02', NULL, NULL, NULL, NULL),
    ('SF-2026-000011', 'cb.riberanorte@correo.test',   'enviado',     '2026-10-06 10:00+02', NULL, NULL, NULL, 'Entregar en el pabellón municipal'),
    ('SF-2026-000012', 'paula.romero@correo.test',     'enviado',     '2026-10-14 21:05+02', NULL, NULL, NULL, NULL),
    ('SF-2026-000013', 'cliente@kalma.test',           'enviado',     '2026-10-21 18:30+02', NULL, NULL, NULL, NULL),
    ('SF-2026-000014', 'asfalto.crew@correo.test',     'enviado',     '2026-10-27 12:00+01', NULL, NULL, NULL, NULL),
    ('SF-2026-000015', 'javier.ortega@correo.test',    'preparacion', '2026-11-03 09:40+01', NULL, NULL, NULL, NULL),
    ('SF-2026-000016', 'daniel.vidal@correo.test',     'preparacion', '2026-11-04 17:25+01', NULL, NULL, NULL, 'Dos pares iguales: uno es para mi hermano'),
    ('SF-2026-000017', 'cd.lasencinas@correo.test',    'preparacion', '2026-11-05 11:10+01', NULL, NULL, NULL, 'Par para el capitán del primer equipo'),
    ('SF-2026-000018', 'irene.castro@correo.test',     'confirmado',  '2026-11-06 20:50+01', NULL, NULL, NULL, NULL),
    ('SF-2026-000019', 'hugo.serrano@correo.test',     'confirmado',  '2026-11-07 13:35+01', NULL, NULL, NULL, NULL),
    ('SF-2026-000020', 'sara.medina@correo.test',      'cancelado',   '2026-11-07 18:00+01', 'confirmado', 'cliente', 'Cancelado por la clienta: ya no la necesita', NULL),
    ('SF-2026-000021', 'cliente@kalma.test',           'pendiente',   '2026-11-08 19:15+01', NULL, NULL, NULL, NULL),
    ('SF-2026-000022', 'marcos.gil@correo.test',       'pendiente',   '2026-11-08 22:40+01', NULL, NULL, NULL, NULL),
    ('SF-2026-000023', 'urbanrunners.sur@correo.test', 'pendiente',   '2026-11-09 10:20+01', NULL, NULL, NULL, 'Para el cuerpo técnico'),
    ('SF-2026-000024', 'paula.romero@correo.test',     'cancelado',   '2026-11-09 12:05+01', 'pendiente',  'cliente', 'Cancelado por la clienta: pedido duplicado', NULL);

-- Líneas: qué zapatilla (código Nike), qué talla y cuántos pares
CREATE TEMP TABLE semilla_lineas (
    codigo_pedido VARCHAR(20)  NOT NULL,
    codigo_marca  VARCHAR(10)  NOT NULL,
    talla         NUMERIC(3,1) NOT NULL,
    cantidad      INTEGER      NOT NULL
) ON COMMIT DROP;

INSERT INTO semilla_lineas VALUES
    ('SF-2026-000001', 'CW2288-111', 39,   1),
    ('SF-2026-000002', 'CW2288-111', 41,   2),
    ('SF-2026-000002', 'CW2288-111', 42,   3),
    ('SF-2026-000002', 'CW2288-111', 43,   2),
    ('SF-2026-000003', 'CT1685-100', 42,   1),
    ('SF-2026-000003', 'DM4044-108', 42,   1),
    ('SF-2026-000004', 'DD1391-100', 39,   1),
    ('SF-2026-000005', 'BQ6806-100', 40,   1),
    ('SF-2026-000006', 'DH6927-111', 43,   1),
    ('SF-2026-000007', 'DM4044-108', 40,   2),
    ('SF-2026-000007', 'DM4044-108', 41,   3),
    ('SF-2026-000007', 'DM4044-108', 42,   3),
    ('SF-2026-000007', 'DM4044-108', 43,   2),
    ('SF-2026-000008', 'CW2288-001', 40,   1),
    ('SF-2026-000008', 'DD1391-103', 40,   1),
    ('SF-2026-000009', 'DM0028-002', 44,   1),
    ('SF-2026-000010', 'DZ5485-612', 43,   1),
    ('SF-2026-000011', 'CW2288-001', 42,   2),
    ('SF-2026-000011', 'CW2288-001', 43,   3),
    ('SF-2026-000011', 'CW2288-001', 44,   2),
    ('SF-2026-000012', 'DQ3989-100', 39,   1),
    ('SF-2026-000013', 'DN3707-100', 40.5, 1),
    ('SF-2026-000014', 'DD1391-100', 41,   1),
    ('SF-2026-000014', 'DD1391-100', 42,   2),
    ('SF-2026-000014', 'DD1391-100', 43,   1),
    ('SF-2026-000015', 'CW2288-111', 44,   1),
    ('SF-2026-000015', 'BQ6806-100', 44,   1),
    ('SF-2026-000016', 'CT1685-100', 43,   2),
    ('SF-2026-000017', 'BQ6806-100', 41,   1),
    ('SF-2026-000018', 'DD1391-103', 39,   1),
    ('SF-2026-000019', 'CW2288-111', 45,   1),
    ('SF-2026-000020', 'DM0028-002', 41,   1),
    ('SF-2026-000021', 'CW2288-111', 40,   1),
    ('SF-2026-000021', 'DD1391-100', 40,   1),
    ('SF-2026-000022', 'DM4044-108', 44,   1),
    ('SF-2026-000023', 'DH6927-111', 42,   1),
    ('SF-2026-000024', 'CW2288-001', 39,   1);

-- Personalizaciones. Se aplican a TODAS las líneas del pedido (un club quiere
-- el mismo escudo en todos los pares). El recargo de la opción es por par.
CREATE TEMP TABLE semilla_personalizacion (
    codigo_pedido VARCHAR(20)  NOT NULL,
    tipo          VARCHAR(30)  NOT NULL,
    opcion        VARCHAR(100) NOT NULL,
    texto         VARCHAR(30)
) ON COMMIT DROP;

INSERT INTO semilla_personalizacion VALUES
    ('SF-2026-000002', 'logo',           'Escudo del club o equipo en el lateral', NULL),
    ('SF-2026-000002', 'color_cordones', 'Cordones con los colores del club',      NULL),
    ('SF-2026-000004', 'texto',          'Nombre bordado en el talón',             'LUCÍA'),
    ('SF-2026-000007', 'logo',           'Escudo del club o equipo en el lateral', NULL),
    ('SF-2026-000007', 'color_cordones', 'Cordones rojos',                         NULL),
    ('SF-2026-000011', 'logo',           'Escudo del club o equipo en el lateral', NULL),
    ('SF-2026-000011', 'color_suela',    'Suela de goma natural',                  NULL),
    ('SF-2026-000014', 'color_suela',    'Suela de goma natural',                  NULL),
    ('SF-2026-000014', 'texto',          'Nombre bordado en el talón',             'ASFALTO CREW'),
    ('SF-2026-000017', 'dorsal',         'Dorsal en la lengüeta',                  '10'),
    ('SF-2026-000017', 'logo',           'Escudo del club o equipo en el lateral', NULL);

-- Recorrido normal de un pedido y cuánto tarda cada paso desde que se crea
CREATE TEMP TABLE flujo_estados (estado, paso, desfase) ON COMMIT DROP AS
VALUES ('pendiente',   1, interval '0'),
       ('confirmado',  2, interval '2 hours'),
       ('preparacion', 3, interval '1 day'),
       ('enviado',     4, interval '2 days'),
       ('entregado',   5, interval '5 days');

-- Personal que mueve los pedidos
CREATE TEMP TABLE semilla_personal ON COMMIT DROP AS
SELECT (SELECT id FROM usuarios WHERE email = 'admin@kalma.test')      AS admin_id,
       (SELECT id FROM usuarios WHERE email = 'almacen@kalma.test')    AS carlos_id,
       (SELECT id FROM usuarios WHERE email = 'marta.leon@kalma.test') AS marta_id;

-- Las fechas de la semilla son históricas. Desactivamos SOLO durante esta carga
-- el trigger que pondría fecha_actualizacion = now() al calcular los totales.
-- (Si algo falla, el ROLLBACK también deshace este ALTER.)
ALTER TABLE pedidos DISABLE TRIGGER trg_pedidos_actualizacion;

-- -----------------------------------------------------------------------------
-- 2. Pedidos, líneas y personalizaciones
-- -----------------------------------------------------------------------------
INSERT INTO pedidos (codigo, cliente_id, estado, personalizado, direccion_envio,
                     codigo_seguimiento, notas, fecha_creacion, fecha_actualizacion)
SELECT s.codigo, c.id, s.estado,
       EXISTS (SELECT 1 FROM semilla_personalizacion sp WHERE sp.codigo_pedido = s.codigo),
       -- Copia de la dirección del cliente en el momento del pedido
       c.direccion || ', ' || c.codigo_postal || ' ' || c.ciudad || ' (' || c.provincia || ')',
       -- Solo los pedidos que ya salieron del almacén tienen número de seguimiento
       CASE WHEN s.estado IN ('enviado', 'entregado') THEN 'TRK' || right(s.codigo, 6) || 'ES' END,
       s.notas, s.fecha, s.fecha
FROM semilla_pedidos s
JOIN usuarios u ON lower(u.email) = lower(s.email_cliente)
JOIN clientes c ON c.usuario_id = u.id
ORDER BY s.fecha;

-- El precio aplicado se copia del catálogo, junto con el nombre, la talla y el color
INSERT INTO lineas_pedido (pedido_id, variante_id, nombre_producto, talla, color, cantidad, precio_unitario)
SELECT p.id, v.id, pr.nombre, v.talla, pr.colorway, sl.cantidad, pr.precio
FROM semilla_lineas sl
JOIN pedidos p    ON p.codigo = sl.codigo_pedido
JOIN productos pr ON pr.codigo_marca = sl.codigo_marca
JOIN variantes v  ON v.producto_id = pr.id AND v.talla = sl.talla
ORDER BY p.id, pr.id, v.talla;

INSERT INTO linea_personalizacion (linea_id, opcion_id, texto, recargo_aplicado)
SELECT l.id, o.id, sp.texto, o.recargo
FROM semilla_personalizacion sp
JOIN pedidos p       ON p.codigo = sp.codigo_pedido
JOIN lineas_pedido l ON l.pedido_id = p.id
JOIN opciones_personalizacion o ON o.tipo = sp.tipo AND o.nombre = sp.opcion
ORDER BY l.id, o.id;

-- Recargo por par de cada línea = suma de los recargos de sus opciones
UPDATE lineas_pedido l
SET recargo_personalizacion = s.recargo
FROM (SELECT linea_id, sum(recargo_aplicado) AS recargo
      FROM linea_personalizacion
      GROUP BY linea_id) s
WHERE l.id = s.linea_id;

-- -----------------------------------------------------------------------------
-- 3. Historial de estados: todos los pasos hasta el estado actual
-- -----------------------------------------------------------------------------
INSERT INTO historial_estados_pedido (pedido_id, estado_anterior, estado_nuevo, usuario_id, comentario, fecha)
SELECT p.id,
       lag(r.estado) OVER (PARTITION BY p.id ORDER BY r.fecha),
       r.estado,
       CASE r.estado
            WHEN 'pendiente'   THEN c.usuario_id     -- lo crea el cliente
            WHEN 'confirmado'  THEN per.admin_id     -- administración verifica el pago
            WHEN 'preparacion' THEN per.carlos_id    -- almacén
            WHEN 'enviado'     THEN per.marta_id     -- almacén
            WHEN 'entregado'   THEN per.carlos_id    -- almacén registra la entrega
            WHEN 'cancelado'   THEN CASE s.cancelado_por WHEN 'cliente' THEN c.usuario_id
                                                         ELSE per.admin_id END
       END,
       CASE r.estado
            WHEN 'pendiente'   THEN 'Pedido creado por el cliente'
            WHEN 'confirmado'  THEN 'Pago verificado'
            WHEN 'preparacion' THEN 'En preparación en almacén'
            WHEN 'enviado'     THEN 'Entregado al transportista'
            WHEN 'entregado'   THEN 'Entregado al cliente'
            WHEN 'cancelado'   THEN s.motivo
       END,
       r.fecha
FROM pedidos p
JOIN semilla_pedidos s ON s.codigo = p.codigo
JOIN clientes c        ON c.id = p.cliente_id
CROSS JOIN semilla_personal per
CROSS JOIN LATERAL (
    -- Pasos del flujo hasta el estado actual (o hasta el estado desde el que se canceló)...
    SELECT f.estado, p.fecha_creacion + f.desfase AS fecha
    FROM flujo_estados f
    WHERE f.paso <= (SELECT paso FROM flujo_estados
                     WHERE estado = coalesce(s.cancelado_desde, s.estado))
    UNION ALL
    -- ...y, si se canceló, la cancelación
    SELECT 'cancelado',
           p.fecha_creacion + CASE s.cancelado_desde WHEN 'pendiente' THEN interval '3 hours'
                                                     ELSE interval '1 day' END
    WHERE s.estado = 'cancelado'
) r
ORDER BY r.fecha, p.id;

-- Total = suma de las líneas · fecha de actualización = último cambio de estado
UPDATE pedidos p
SET total = l.total,
    fecha_actualizacion = h.ultima
FROM (SELECT pedido_id, sum(importe) AS total FROM lineas_pedido GROUP BY pedido_id) l,
     (SELECT pedido_id, max(fecha) AS ultima FROM historial_estados_pedido GROUP BY pedido_id) h
WHERE l.pedido_id = p.id
  AND h.pedido_id = p.id;

ALTER TABLE pedidos ENABLE TRIGGER trg_pedidos_actualizacion;

-- -----------------------------------------------------------------------------
-- 4. Stock: cada pedido descuenta sus pares al crearse y la cancelación los
--    devuelve. stock_resultante se calcula en orden cronológico por variante.
-- -----------------------------------------------------------------------------
WITH movimientos AS (
    SELECT l.variante_id, 'venta' AS tipo, -l.cantidad AS cantidad,
           p.id AS pedido_id, c.usuario_id, 'Venta · pedido ' || p.codigo AS motivo,
           p.fecha_creacion AS fecha
    FROM lineas_pedido l
    JOIN pedidos p  ON p.id = l.pedido_id
    JOIN clientes c ON c.id = p.cliente_id
    UNION ALL
    SELECT l.variante_id, 'cancelacion', l.cantidad,
           p.id, h.usuario_id, 'Cancelación · pedido ' || p.codigo,
           h.fecha
    FROM lineas_pedido l
    JOIN pedidos p ON p.id = l.pedido_id
    JOIN historial_estados_pedido h ON h.pedido_id = p.id AND h.estado_nuevo = 'cancelado'
)
INSERT INTO movimientos_stock (variante_id, tipo, cantidad, stock_resultante, pedido_id, usuario_id, motivo, fecha)
SELECT m.variante_id, m.tipo, m.cantidad,
       -- stock inicial (del catálogo) + todos los movimientos anteriores y este
       v.stock + sum(m.cantidad) OVER (PARTITION BY m.variante_id
                                       ORDER BY m.fecha, m.pedido_id
                                       ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW),
       m.pedido_id, m.usuario_id, m.motivo, m.fecha
FROM movimientos m
JOIN variantes v ON v.id = m.variante_id
ORDER BY m.fecha, m.variante_id;

-- Stock final = stock inicial + ventas (negativas) + cancelaciones (positivas).
-- Si alguna talla se vendiera por encima de su stock, el CHECK (stock >= 0) lo impide.
UPDATE variantes v
SET stock = v.stock + d.delta
FROM (SELECT variante_id, sum(cantidad) AS delta
      FROM movimientos_stock
      WHERE tipo IN ('venta', 'cancelacion')
      GROUP BY variante_id) d
WHERE v.id = d.variante_id;

-- -----------------------------------------------------------------------------
-- 5. Auditoría: cada cambio de estado es un evento de negocio...
-- -----------------------------------------------------------------------------
INSERT INTO auditoria (usuario_id, tipo_evento, entidad, entidad_id, resultado, detalle, fecha)
SELECT h.usuario_id,
       CASE WHEN h.estado_anterior IS NULL   THEN 'pedido.crear'
            WHEN h.estado_nuevo = 'cancelado' THEN 'pedido.cancelar'
            ELSE 'pedido.cambiar_estado'
       END,
       'pedido', h.pedido_id, 'exito',
       jsonb_build_object('codigo', p.codigo, 'de', h.estado_anterior, 'a', h.estado_nuevo),
       h.fecha
FROM historial_estados_pedido h
JOIN pedidos p ON p.id = h.pedido_id
ORDER BY h.fecha, h.id;

-- ...y también se registran los intentos denegados (IP de red local ficticia)
INSERT INTO auditoria (usuario_id, tipo_evento, entidad, entidad_id, resultado, detalle, ip, fecha)
SELECT u.id, e.tipo_evento, e.entidad,
       CASE e.entidad WHEN 'pedido' THEN p.id ELSE u.id END,
       'denegado', e.detalle::jsonb, e.ip::inet, e.fecha::timestamptz
FROM (VALUES
    ('cliente@kalma.test',     'pedido.cancelar', 'pedido',  'SF-2026-000013',
     '{"motivo": "un pedido enviado ya no se puede cancelar"}', '192.168.1.34', '2026-10-24 10:12+02'),
    ('marcos.gil@correo.test', 'pedido.ver',      'pedido',  'SF-2026-000021',
     '{"motivo": "el pedido pertenece a otro cliente"}',        '192.168.1.51', '2026-11-09 09:03+01'),
    ('tomas.vera@correo.test', 'auth.login',      'usuario', NULL,
     '{"motivo": "cuenta desactivada"}',                        '10.0.0.27',    '2026-10-30 08:45+01')
) AS e(email, tipo_evento, entidad, codigo_pedido, detalle, ip, fecha)
JOIN usuarios u     ON u.email = e.email
LEFT JOIN pedidos p ON p.codigo = e.codigo_pedido
ORDER BY e.fecha::timestamptz;

-- -----------------------------------------------------------------------------
-- 6. Comprobaciones: si algo no cuadra, la carga se aborta
-- -----------------------------------------------------------------------------
DO $$
BEGIN
    -- Los JOIN con las tablas temporales descartan filas sin avisar si un email,
    -- un código o una talla no existen: comprobamos que no se ha perdido nada.
    IF (SELECT count(*) FROM pedidos) <> (SELECT count(*) FROM semilla_pedidos) THEN
        RAISE EXCEPTION 'Faltan pedidos: algún email de cliente no existe';
    END IF;
    IF (SELECT count(*) FROM lineas_pedido) <> (SELECT count(*) FROM semilla_lineas) THEN
        RAISE EXCEPTION 'Faltan líneas: algún código de marca o talla no existe en el catálogo';
    END IF;
    IF (SELECT count(*) FROM linea_personalizacion) <>
       (SELECT count(*) FROM semilla_personalizacion sp
        JOIN pedidos p       ON p.codigo = sp.codigo_pedido
        JOIN lineas_pedido l ON l.pedido_id = p.id) THEN
        RAISE EXCEPTION 'Faltan personalizaciones: alguna opción no existe';
    END IF;

    -- El total de cada pedido es la suma de sus líneas
    IF EXISTS (SELECT 1 FROM pedidos p
               WHERE p.total <> (SELECT sum(l.importe) FROM lineas_pedido l WHERE l.pedido_id = p.id)) THEN
        RAISE EXCEPTION 'Hay pedidos cuyo total no coincide con la suma de sus líneas';
    END IF;

    -- El estado de cada pedido es el último de su historial
    IF EXISTS (SELECT 1 FROM pedidos p
               WHERE p.estado <> (SELECT h.estado_nuevo FROM historial_estados_pedido h
                                  WHERE h.pedido_id = p.id
                                  ORDER BY h.fecha DESC, h.id DESC LIMIT 1)) THEN
        RAISE EXCEPTION 'Hay pedidos cuyo estado no coincide con su historial';
    END IF;

    -- El stock de cada talla es exactamente la suma de sus movimientos
    IF EXISTS (SELECT 1 FROM variantes v
               WHERE v.stock <> (SELECT coalesce(sum(m.cantidad), 0) FROM movimientos_stock m
                                 WHERE m.variante_id = v.id)) THEN
        RAISE EXCEPTION 'El stock de alguna talla no cuadra con sus movimientos';
    END IF;

    -- personalizado = el pedido tiene alguna opción elegida
    IF EXISTS (SELECT 1 FROM pedidos p
               WHERE p.personalizado <> EXISTS (SELECT 1 FROM linea_personalizacion lp
                                                JOIN lineas_pedido l ON l.id = lp.linea_id
                                                WHERE l.pedido_id = p.id)) THEN
        RAISE EXCEPTION 'La marca de pedido personalizado no coincide con sus líneas';
    END IF;

    -- Solo se personalizan productos personalizables, con opciones activas
    -- y con texto cuando la opción lo exige
    IF EXISTS (SELECT 1 FROM linea_personalizacion lp
               JOIN lineas_pedido l            ON l.id = lp.linea_id
               JOIN variantes v                ON v.id = l.variante_id
               JOIN productos pr               ON pr.id = v.producto_id
               JOIN opciones_personalizacion o ON o.id = lp.opcion_id
               WHERE NOT pr.personalizable
                  OR NOT o.activo
                  OR (o.requiere_texto AND lp.texto IS NULL)) THEN
        RAISE EXCEPTION 'Hay personalizaciones no válidas';
    END IF;

    -- Mínimos del enunciado: 10 clientes, 20 pedidos y 5 personalizados
    IF (SELECT count(*) FROM clientes) < 10
       OR (SELECT count(*) FROM pedidos) < 20
       OR (SELECT count(*) FROM pedidos WHERE personalizado) < 5 THEN
        RAISE EXCEPTION 'La semilla no cumple los mínimos del enunciado';
    END IF;
END $$;

COMMIT;
