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
-- Productos: 14 combinaciones de color de 12 modelos distintos a la venta
-- (el enunciado pide un mínimo de 8 modelos) y 7 lanzamientos anunciados.
-- El Air Max 95 «Neon» y el Air Max 1 x Patta van al final de la lista, después
-- de los lanzamientos, para no mover los id que ya usan los pedidos y las pruebas.
--
-- Los lanzamientos llevan fecha futura, calculada desde el día en que se carga
-- la semilla: así siempre hay próximos lanzamientos en la portada, se monte la
-- base de datos cuando se monte. Los tres primeros son lanzamientos reales de
-- noviembre de 2026 y, cargando la semilla el 11/11/2026, caen en su fecha real
-- en Europa: Jordan 11 «Magic Ball» el 12, Shox R419 x Corteiz el 20 y
-- Jordan 4 «Bred» el 27. Los otros cuatro son reediciones ya publicadas a las
-- que KALMA pone su propia fecha de llegada.
-- -----------------------------------------------------------------------------
INSERT INTO productos (categoria_id, marca_id, color_id, nombre, colorway, codigo_marca,
                       slug, descripcion, precio, personalizable, imagen, fecha_lanzamiento,
                       fecha_creacion, fecha_actualizacion)
SELECT cat.id, m.id, col.id, p.nombre, p.colorway, p.codigo,
       -- Slug a partir del nombre y el código: "Nike Air Force 1 '07" + "CW2288-111"
       -- -> nike-air-force-1-07-cw2288-111 (sin escribirlo a mano, sin errores)
       trim(BOTH '-' FROM regexp_replace(lower(p.nombre || ' ' || p.codigo), '[^a-z0-9]+', '-', 'g')),
       p.descripcion, p.precio,
       -- Solo se borda la piel lisa de los Lifestyle. Las Air Max son de malla y
       -- ante, y las Jordan son reediciones de coleccionista: bordarlas les quita valor
       p.categoria = 'Lifestyle',
       'img/productos/' || lower(p.codigo) || '.webp',
       CURRENT_DATE + l.dias_hasta_lanzamiento,          -- NULL si ya está a la venta
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
         'Apodada «Military Black» (2022): los bloques de color del «Military Blue» de 1989 en negro y gris neutro sobre piel blanca.'),
    -- Lanzamientos anunciados (todavía sin stock)
    (13, 'Jordan',    'Negro',  'Air Jordan 11 Retro',          'Magic Ball',
         'IZ1800-200', 249.99,
         'Apodada «Magic Ball» (2026): charol tornasolado que cambia de dorado a verde y morado, por los 30 años de Space Jam y el balón de los Monstars.'),
    (14, 'Lifestyle', 'Plata',  'Nike Shox R419 x Corteiz',     'Metallic Silver',
         'IQ9951-001', 189.99,
         'La Shox R4 del año 2000 reinterpretada por la marca londinense Corteiz: pala plateada con relieve y los cuatro pilares de amortiguación en el talón.'),
    (15, 'Jordan',    'Negro',  'Air Jordan 4 Retro OG',        'Black/Fire Red/Cement Grey/White',
         'IX8180-001', 239.99,
         'Apodada «Bred» (2026): vuelve el negro y rojo original de 1989 en nobuk, con Nike Air en el talón. Sale el Black Friday.'),
    (16, 'Jordan',    'Negro',  'Air Jordan 4 Retro',           'Black/Fire Red-Cement Grey-Summit White',
         'FV5029-006', 209.99,
         'Apodada «Bred Reimagined» (2024): el negro y rojo de 1989 con la pala en piel en lugar de nobuk.'),
    (17, 'Jordan',    'Blanco', 'Air Jordan 11 Retro',          'White/Legend Blue-Black',
         'CT8012-104', 229.99,
         'Apodada «Legend Blue» (2024): charol blanco, suela translúcida azul hielo y el Jumpman en azul leyenda del All-Star de 1996.'),
    (18, 'Jordan',    'Negro',  'Air Jordan 3 Retro',           'Black/Fire Red-Cement Grey-Summit White',
         'DN3707-010', 209.99,
         'Apodada «Black Cement Reimagined» (2024): piel negra, estampado de elefante y el logo Nike Air en el talón como en el par de 1988.'),
    (19, 'Jordan',    'Negro',  'Air Jordan 5 Retro OG',        'Black/Metallic Silver-Fire Red',
         'HF3975-001', 209.99,
         'Apodada «Black Metallic Reimagined» (2025): nobuk negro, lengüeta reflectante plateada y Nike Air bordado en el talón.'),
    -- Incorporaciones al catálogo a la venta
    (20, 'Air Max',   'Gris',   'Nike Air Max 95 OG',           'Black/Neon Yellow-Light Graphite',
         'CT1689-001', 189.99,
         'Reedición de 2020 del «Neon» original de 1995: capas de malla y ante en degradado de negro a gris, como las costillas de un cuerpo, ojales en amarillo neón y cámaras Air visibles en el talón y la puntera.'),
    (21, 'Air Max',   'Azul',   'Nike Air Max 1 x Patta',       'Metallic Silver/Noise Aqua/Pure Platinum',
         'DH1348-004', 179.99,
         'Colaboración «The Wave» con la tienda de Ámsterdam Patta (2021): refuerzo de ante ondulado en azul aqua, malla gris y Swoosh bordado en la puntera.')
) AS p(orden, categoria, color, nombre, colorway, codigo, precio, descripcion)
-- Días que faltan para el lanzamiento, por código (los que no están aquí ya se venden)
LEFT JOIN (VALUES ('IZ1800-200', 1), ('IQ9951-001', 9), ('IX8180-001', 16),
                  ('FV5029-006', 24), ('CT8012-104', 31), ('DN3707-010', 38), ('HF3975-001', 45))
       AS l(codigo, dias_hasta_lanzamiento) ON l.codigo = p.codigo
JOIN categorias cat ON cat.nombre = p.categoria
JOIN colores col    ON col.nombre = p.color
JOIN marcas m       ON m.nombre = 'Nike'
ORDER BY p.orden;   -- los id siguen el orden de la lista

-- -----------------------------------------------------------------------------
-- Variantes: 9 tallas por producto (189 en total; las 63 de los lanzamientos
-- se crean con stock 0 y se llenarán con el albarán cuando lleguen)
--
-- Stock inicial = base del producto × factor de la talla (hay más pares de las
-- tallas centrales). Algunos casos están preparados a propósito para las demos:
--   * Las Jordan, el Air Max 97 y el Air Max 1 x Patta tienen poco stock
--     (informe "menos existencias")
--   * Air Jordan 1 en talla 42 está agotada (stock 0) para probar "pedido sin stock"
-- -----------------------------------------------------------------------------
WITH base(codigo, stock_base) AS (VALUES
    ('CW2288-111', 20), ('CW2288-001', 14), ('DD1391-100', 16), ('DD1391-103', 10),
    ('CT1685-100',  9), ('DM0028-002',  5), ('DQ3989-100',  6), ('BQ6806-100', 12),
    ('DM4044-108', 10), ('DZ5485-612',  3), ('DN3707-100',  4), ('DH6927-111',  2),
    ('IZ1800-200',  0), ('IQ9951-001',  0), ('IX8180-001',  0),
    ('FV5029-006',  0), ('CT8012-104',  0), ('DN3707-010',  0), ('HF3975-001',  0),
    ('CT1689-001',  7), ('DH1348-004',  4)
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
-- Opciones de personalización (recargo por par). La caja de madera está
-- desactivada para probar que el servidor rechaza opciones no disponibles.
-- -----------------------------------------------------------------------------
INSERT INTO opciones_personalizacion (tipo, nombre, descripcion, afecta_zapatilla, max_caracteres, recargo, activo) VALUES
    ('packaging',   'Caja edición KALMA',           'Caja negra mate con el logo de KALMA en relieve',                         false, NULL, 12.00, true),
    ('packaging',   'Caja de coleccionista',        'Caja con frontal transparente para exponer el par',                       false, NULL, 25.00, true),
    ('packaging',   'Caja de madera grabada',       'Edición limitada agotada',                                                false, NULL, 40.00, false),
    ('bordado',     'Nombre bordado en el talón',   'Hasta 12 letras bordadas en el talón de cada zapatilla',                  true,  12,   15.00, true),
    ('cordones',    'Cordones de repuesto negros',  'Un par de cordones extra en la caja',                                     false, NULL,  5.00, true),
    ('cordones',    'Cordones de repuesto blancos', 'Un par de cordones extra en la caja',                                     false, NULL,  5.00, true),
    ('cordones',    'Cordones de repuesto rojos',   'Un par de cordones extra en la caja',                                     false, NULL,  5.00, true),
    ('certificado', 'Certificado de autenticidad',  'Tarjeta con el código de estilo del modelo y la etiqueta RFID de la talla', false, NULL,  9.00, true);

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
