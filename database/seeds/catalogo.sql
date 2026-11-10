-- =============================================================================
-- KALMA SNKRS · Datos semilla 2/3: catálogo Nike
--
-- compose.yaml lo monta como /docker-entrypoint-initdb.d/03_catalogo.sql
-- (después de usuarios, porque la entrada inicial de stock la registra el
-- empleado de almacén).
--
-- Las zapatillas son productos Nike REALES. codigo_marca es el código de estilo
-- oficial de cada una, verificado en nike.com y en tiendas como GOAT, StockX o
-- Kick Game (noviembre de 2026). Los precios son PVP orientativos en euros.
-- =============================================================================

BEGIN;

-- -----------------------------------------------------------------------------
-- Marca, categorías y colores
-- -----------------------------------------------------------------------------
-- Jordan Brand pertenece a Nike y sus códigos de estilo son códigos Nike,
-- así que todas las zapatillas son de la marca Nike y "Jordan" es una categoría.
INSERT INTO marcas (nombre, pais) VALUES ('Nike', 'Estados Unidos');

INSERT INTO categorias (nombre, descripcion) VALUES
    ('Lifestyle', 'Clásicos de calle: Air Force 1, Dunk, Blazer y Cortez'),
    ('Air Max',   'Modelos con cámara de aire visible'),
    ('Jordan',    'Reediciones de la línea Air Jordan');

-- Color principal = el color con el que un cliente buscaría la zapatilla
INSERT INTO colores (nombre, codigo_hex) VALUES
    ('Blanco', '#FFFFFF'),
    ('Negro',  '#111111'),
    ('Gris',   '#9E9E9E'),
    ('Rojo',   '#C8102E'),
    ('Plata',  '#C0C0C0'),
    ('Azul',   '#1F4E9C');

-- -----------------------------------------------------------------------------
-- Productos: 12 combinaciones de color de 10 modelos distintos
-- (el enunciado pide un mínimo de 8 modelos)
-- -----------------------------------------------------------------------------
INSERT INTO productos (categoria_id, marca_id, color_id, nombre, colorway, codigo_marca,
                       slug, descripcion, precio, imagen,
                       fecha_creacion, fecha_actualizacion)
SELECT cat.id, m.id, col.id, p.nombre, p.colorway, p.codigo,
       -- Slug a partir del nombre y el código: "Nike Air Force 1 '07" + "CW2288-111"
       -- -> nike-air-force-1-07-cw2288-111 (sin escribirlo a mano, sin errores)
       trim(BOTH '-' FROM regexp_replace(lower(p.nombre || ' ' || p.codigo), '[^a-z0-9]+', '-', 'g')),
       p.descripcion, p.precio,
       'img/productos/' || lower(p.codigo) || '.webp',   -- las fotos llegan en la fase de front
       '2026-06-15 08:00+02', '2026-06-15 08:00+02'
FROM (VALUES
    (1,  'Lifestyle', 'Blanco', 'Nike Air Force 1 ''07',        'White/White',
         'CW2288-111', 119.99,
         'El clásico de baloncesto de 1982 en su versión más icónica: piel blanca de arriba abajo, perforaciones en la puntera y amortiguación Nike Air.'),
    (2,  'Lifestyle', 'Negro',  'Nike Air Force 1 ''07',        'Black/Black',
         'CW2288-001', 119.99,
         'El Air Force 1 en negro total: piel, mediasuela y suela negras con la misma amortiguación Nike Air del modelo original.'),
    (3,  'Lifestyle', 'Negro',  'Nike Dunk Low Retro',          'White/Black-White',
         'DD1391-100', 119.99,
         'Apodada «Panda». La Dunk nació en 1985 como zapatilla de baloncesto universitario y esta combinación blanca y negra es una de las más vendidas de los últimos años.'),
    (4,  'Lifestyle', 'Gris',   'Nike Dunk Low Retro',          'White/Grey Fog',
         'DD1391-103', 119.99,
         'Apodada «Grey Fog»: piel blanca con refuerzos en gris niebla. Una Dunk sobria para el día a día.'),
    (5,  'Air Max',   'Rojo',   'Nike Air Max 90',              'White/Black-Cool Grey-Radiant Red',
         'CT1685-100', 149.99,
         'Reedición de 2020 del «Infrared» original de 1990: malla blanca, refuerzos gris y negro y detalles en rojo infrarrojo junto a la cámara de aire visible.'),
    (6,  'Air Max',   'Plata',  'Nike Air Max 97 OG',           'Metallic Silver/University Red-Black-White',
         'DM0028-002', 189.99,
         'Reedición de 2022 del «Silver Bullet»: líneas plateadas inspiradas en los trenes bala japoneses y cámara Air de longitud completa.'),
    (7,  'Air Max',   'Rojo',   'Nike Air Max 1 ''86 OG',       'White/University Red-Light Neutral Grey',
         'DQ3989-100', 159.99,
         'Edición «Big Bubble» del Air Max Day 2023, que recupera la cámara de aire más grande del modelo original de 1986.'),
    (8,  'Lifestyle', 'Blanco', 'Nike Blazer Mid ''77 Vintage', 'White/Black',
         'BQ6806-100', 109.99,
         'Diseño de baloncesto de los años 70: piel blanca, Swoosh negro y mediasuela con acabado envejecido.'),
    (9,  'Lifestyle', 'Azul',   'Nike Cortez Leather',          'White/Varsity Blue/Varsity Red',
         'DM4044-108', 99.99,
         'La zapatilla de running con la que empezó Nike en 1972, en piel blanca con Swoosh azul y detalles en rojo.'),
    (10, 'Jordan',    'Rojo',   'Air Jordan 1 Retro High OG',   'Varsity Red/Black/Sail/Muslin',
         'DZ5485-612', 180.00,
         'Apodada «Lost & Found» (2022): el colorido Chicago con piel craquelada y tonos envejecidos, como un par olvidado en el almacén de una tienda de los 80.'),
    (11, 'Jordan',    'Blanco', 'Air Jordan 3 Retro',           'Summit White/Fire Red/Black/Cement Grey',
         'DN3707-100', 209.99,
         'Apodada «White Cement Reimagined» (2023): piel blanca, estampado de elefante en gris cemento y mediasuela envejecida por el 35 aniversario del modelo.'),
    (12, 'Jordan',    'Gris',   'Air Jordan 4 Retro',           'White/Black-Neutral Grey',
         'DH6927-111', 199.99,
         'Apodada «Military Black» (2022): los bloques de color del «Military Blue» de 1989 en negro y gris neutro sobre piel blanca.')
) AS p(orden, categoria, color, nombre, colorway, codigo, precio, descripcion)
JOIN categorias cat ON cat.nombre = p.categoria
JOIN colores col    ON col.nombre = p.color
JOIN marcas m       ON m.nombre = 'Nike'
ORDER BY p.orden;   -- los id siguen el orden de la lista

-- -----------------------------------------------------------------------------
-- Variantes: 9 tallas por producto (108 en total)
--
-- Stock inicial = base del producto × factor de la talla (hay más pares de las
-- tallas centrales). Algunos casos están preparados a propósito para las demos:
--   * Las Jordan y el Air Max 97 tienen poco stock (informe "menos existencias")
--   * Air Jordan 1 en talla 42 está agotada (stock 0) para probar "pedido sin stock"
-- -----------------------------------------------------------------------------
WITH base(codigo, stock_base) AS (VALUES
    ('CW2288-111', 20), ('CW2288-001', 14), ('DD1391-100', 16), ('DD1391-103', 10),
    ('CT1685-100',  9), ('DM0028-002',  5), ('DQ3989-100',  6), ('BQ6806-100', 12),
    ('DM4044-108', 10), ('DZ5485-612',  3), ('DN3707-100',  4), ('DH6927-111',  2)
), tallas(talla, factor) AS (VALUES
    (39.0, 0.5), (40.0, 0.8), (40.5, 0.8), (41.0, 1.2), (42.0, 1.4),
    (42.5, 1.2), (43.0, 1.2), (44.0, 0.8), (45.0, 0.5)
)
INSERT INTO variantes (producto_id, talla, stock, rfid_tienda)
SELECT p.id, t.talla,
       CASE WHEN p.codigo_marca = 'DZ5485-612' AND t.talla = 42 THEN 0
            ELSE round(b.stock_base * t.factor)::int
       END,
       -- RFID de la tienda: EPC propio de KALMA de 24 caracteres hexadecimales
       --   4B4C4D  -> "KLM" en ASCII (prefijo de la tienda)
       --   000001  -> id del producto
       --   01A9    -> talla × 10 (42.5 -> 425 -> 0x1A9)
       --   00000001 -> número de serie
       '4B4C4D' || lpad(upper(to_hex(p.id)), 6, '0')
                || lpad(upper(to_hex((t.talla * 10)::int)), 4, '0')
                || '00000001'
FROM productos p
JOIN base b ON b.codigo = p.codigo_marca
CROSS JOIN tallas t
ORDER BY p.id, t.talla;

-- Entrada inicial de inventario: cada talla con stock deja su movimiento,
-- registrado por el empleado de almacén
INSERT INTO movimientos_stock (variante_id, tipo, cantidad, stock_resultante, usuario_id, motivo, fecha)
SELECT v.id, 'entrada', v.stock, v.stock, u.id, 'Carga inicial de inventario', '2026-06-15 09:00+02'
FROM variantes v
JOIN usuarios u ON u.email = 'almacen@kalma.test'
WHERE v.stock > 0
ORDER BY v.id;

-- -----------------------------------------------------------------------------
-- Auditoría: alta de cada producto por la administradora
-- -----------------------------------------------------------------------------
INSERT INTO auditoria (usuario_id, tipo_evento, entidad, entidad_id, resultado, detalle, fecha)
SELECT u.id, 'producto.crear', 'producto', p.id, 'exito',
       jsonb_build_object('codigo_marca', p.codigo_marca, 'precio', p.precio),
       p.fecha_creacion
FROM productos p
JOIN usuarios u ON u.email = 'admin@kalma.test'
ORDER BY p.id;

-- -----------------------------------------------------------------------------
-- Comprobación: si no se cumple, la carga se aborta y el contenedor no arranca
-- -----------------------------------------------------------------------------
DO $$
BEGIN
    -- Un modelo = un prefijo de código de estilo (CW2288-111 y CW2288-001 son el mismo modelo)
    IF (SELECT count(DISTINCT split_part(codigo_marca, '-', 1)) FROM productos) < 8 THEN
        RAISE EXCEPTION 'El catálogo debe tener al menos 8 modelos distintos';
    END IF;
    IF EXISTS (SELECT 1 FROM productos p WHERE NOT EXISTS
               (SELECT 1 FROM variantes v WHERE v.producto_id = p.id)) THEN
        RAISE EXCEPTION 'Hay productos sin tallas';
    END IF;
END $$;

COMMIT;
