-- =============================================================================
-- KALMA SNKRS · Datos semilla 1/3: roles, usuarios y clientes
--
-- compose.yaml lo monta como /docker-entrypoint-initdb.d/02_usuarios.sql
-- (se ejecuta después del esquema y antes del catálogo).
--
-- Todas las personas, clubes y equipos son FICTICIOS. Los emails usan el
-- dominio reservado .test para que nunca puedan ser direcciones reales.
--
-- Usuarios de prueba (uno por perfil, documentados en el README):
--   admin@kalma.test    -> administrador
--   almacen@kalma.test  -> empleado de almacén
--   cliente@kalma.test  -> cliente
-- Contraseña de demostración de todas las cuentas: Kalma2026!
-- Solo se guarda su hash bcrypt (password_hash de PHP, con sal distinta en cada fila).
-- =============================================================================

BEGIN;

-- -----------------------------------------------------------------------------
-- Roles. La matriz de permisos de cada rol está en el middleware PHP.
-- -----------------------------------------------------------------------------
INSERT INTO roles (codigo, nombre, descripcion) VALUES
    ('admin',   'Administrador',       'Gestión completa: catálogo, stock, pedidos, usuarios, informes y auditoría'),
    ('almacen', 'Empleado de almacén', 'Gestiona el stock y avanza el estado de los pedidos (preparación y envío)'),
    ('cliente', 'Cliente',             'Consulta el catálogo y gestiona sus propios pedidos y datos');

-- -----------------------------------------------------------------------------
-- Usuarios: 1 admin, 2 de almacén y 13 clientes (uno de ellos desactivado,
-- para probar que una cuenta inactiva no puede iniciar sesión).
-- -----------------------------------------------------------------------------
INSERT INTO usuarios (rol_id, nombre, apellidos, email, password_hash, activo, fecha_creacion)
SELECT r.id, u.nombre, u.apellidos, u.email, u.hash, u.activo, u.alta::timestamptz
FROM (VALUES
    -- Personal interno
    ('admin',   'Laura',   'Martín Correa',    'admin@kalma.test',                '$2y$10$2KXGTxp2CFt9FWHj/l2BaO5hiNw8la6fJNwKpORN6QEQKrucH58vm', true,  '2026-01-12 09:00+01'),
    ('almacen', 'Carlos',  'Rivas Peña',       'almacen@kalma.test',              '$2y$10$OixLZSaVM/UjxBE3T5u9/OSlGu4tZa.QfGNzxiFjNLtcwxcmsl8RG', true,  '2026-01-12 09:30+01'),
    ('almacen', 'Marta',   'León Gallego',     'marta.leon@kalma.test',           '$2y$10$am19naX3y6/paOIMCrk9Pu14NTB49YJXGdgRpnmR/tDA7Yf4UafY2', true,  '2026-02-02 10:00+01'),
    -- Clientes particulares
    ('cliente', 'Lucía',   'Fernández Ruiz',   'cliente@kalma.test',              '$2y$10$mW11FhHfWQQu4rQPTW125eADUkAPAyCm1u.Uwm7BPgSrv/rdfv1WK', true,  '2026-03-04 18:12+01'),
    ('cliente', 'Marcos',  'Gil Navarro',      'marcos.gil@correo.test',          '$2y$10$Bkg1VYPvJMfvtGZaLQ15DOZ0oN294/PhvvbGyaMhCaaBCf.iGCw3C', true,  '2026-03-15 21:40+01'),
    ('cliente', 'Paula',   'Romero Sanz',      'paula.romero@correo.test',        '$2y$10$0vBL3DnLtPb9Zr6twM0kWOUjsuyDoDpraVQCwPWSjwM7pHCygGGfi', true,  '2026-04-02 12:05+02'),
    ('cliente', 'Javier',  'Ortega Molina',    'javier.ortega@correo.test',       '$2y$10$cK98041.MaDhoST5lLN3jOj18LYTVlIXMTtjXSE6D73L6X7uVkQbW', true,  '2026-04-19 17:33+02'),
    ('cliente', 'Irene',   'Castro Delgado',   'irene.castro@correo.test',        '$2y$10$PIh/wza8yltw9zyrY0qCzepnjgo.uCAQwZA/tpTLnNZeDZHNy9jY2', true,  '2026-05-06 10:47+02'),
    ('cliente', 'Daniel',  'Vidal Herrera',    'daniel.vidal@correo.test',        '$2y$10$3w7evzflKBj1.RWj.VDyo.QOHbKsLYFKRHPwD3h5yLkzsN0KzOsqK', true,  '2026-05-23 19:20+02'),
    ('cliente', 'Sara',    'Medina Ibáñez',    'sara.medina@correo.test',         '$2y$10$FdukyFg85bdIvYOa32LP4ucO3mYcJ/8HFBeuW64fi3qbFSRf5Eyy6', true,  '2026-06-08 16:02+02'),
    ('cliente', 'Hugo',    'Serrano Prieto',   'hugo.serrano@correo.test',        '$2y$10$l1lXKtXc1wQaeOFgsQeFteaPNZCPGYnPLAJ2yA.Dg7WNzZzURQLKC', true,  '2026-06-21 22:15+02'),
    ('cliente', 'Tomás',   'Vera Aguilar',     'tomas.vera@correo.test',          '$2y$10$XO2fTlF2ERAPHIyLCXnCMOEMvR6LX3pxCt4.HfhoqGff.mzpYMF4e', false, '2026-04-11 11:11+02'),
    -- Clubes y equipos (la persona de contacto es quien tiene la cuenta)
    ('cliente', 'Andrés',  'Lozano Rey',       'cd.lasencinas@correo.test',       '$2y$10$Fs1MhlbcbyyCuRDgEdNzH.cCxReGf8J5oyAS759DEKE8xtDvK.GLK', true,  '2026-03-28 09:15+01'),
    ('cliente', 'Elena',   'Pascual Vega',     'cb.riberanorte@correo.test',      '$2y$10$KKxnSPsGEv./ef83QfL0UuTVmcIy/rXXTMMCS6r6BImrLIK5YEpS6', true,  '2026-05-12 13:30+02'),
    ('cliente', 'Rubén',   'Cano Fuentes',     'urbanrunners.sur@correo.test',    '$2y$10$IWhBjV7R2mVupvYg83ZDn.5uHGfFvUycCvecCxfQJDQH/0jwwOEb2', true,  '2026-06-02 20:45+02'),
    ('cliente', 'Nerea',   'Blanco Soler',     'asfalto.crew@correo.test',        '$2y$10$wuEMoo.Jf2GdLhcaQKchQOq0YECelSqAQP.1/ywVkVS0mxEq9CmI2', true,  '2026-06-30 18:00+02')
) AS u(rol, nombre, apellidos, email, hash, activo, alta)
JOIN roles r ON r.codigo = u.rol
ORDER BY u.alta::timestamptz;   -- los id siguen el orden de alta

-- -----------------------------------------------------------------------------
-- Perfil comercial de los clientes. Los NIF son ficticios pero tienen la letra
-- de control correcta, así que pasan la misma validación que hará PHP.
-- El equipo Asfalto Crew no es una entidad legal: no tiene NIF (NULL permitido).
-- -----------------------------------------------------------------------------
INSERT INTO clientes (usuario_id, tipo_cliente, razon_social, nif, telefono,
                      direccion, codigo_postal, ciudad, provincia)
SELECT us.id, c.tipo, c.razon_social, c.nif, c.telefono, c.direccion, c.cp, c.ciudad, c.provincia
FROM (VALUES
    ('cliente@kalma.test',           'particular', NULL,                           '50112233V', '600000101', 'Calle Mayor 12, 2º A',            '28013', 'Madrid',     'Madrid'),
    ('marcos.gil@correo.test',       'particular', NULL,                           '20334455V', '600000102', 'Calle de Colón 8, 4º',            '46004', 'Valencia',   'Valencia'),
    ('paula.romero@correo.test',     'particular', NULL,                           '28445566V', '600000103', 'Avenida de la Constitución 21',   '41001', 'Sevilla',    'Sevilla'),
    ('javier.ortega@correo.test',    'particular', NULL,                           '17556677H', '600000104', 'Paseo de la Independencia 5, 1º', '50001', 'Zaragoza',   'Zaragoza'),
    ('irene.castro@correo.test',     'particular', NULL,                           '25667788H', '600000105', 'Calle Larios 3, 3º B',            '29005', 'Málaga',     'Málaga'),
    ('daniel.vidal@correo.test',     'particular', NULL,                           '46778899G', '600000106', 'Carrer de Pelai 14, 2º 1ª',       '08001', 'Barcelona',  'Barcelona'),
    ('sara.medina@correo.test',      'particular', NULL,                           '16889900B', '600000107', 'Gran Vía 40, 5º D',               '48011', 'Bilbao',     'Bizkaia'),
    ('hugo.serrano@correo.test',     'particular', NULL,                           '12990011W', '600000108', 'Calle Santiago 9, 1º',            '47001', 'Valladolid', 'Valladolid'),
    ('tomas.vera@correo.test',       'particular', NULL,                           '48101122A', '600000109', 'Calle Real 2',                    '30001', 'Murcia',     'Murcia'),
    ('cd.lasencinas@correo.test',    'club',       'Club Deportivo Las Encinas',   'G81234569', '910000201', 'Calle del Polideportivo 1',       '28031', 'Madrid',     'Madrid'),
    ('cb.riberanorte@correo.test',   'club',       'Club Baloncesto Ribera Norte', 'G30234561', '968000202', 'Avenida Juan Carlos I 15',        '30008', 'Murcia',     'Murcia'),
    ('urbanrunners.sur@correo.test', 'equipo',     'Urban Runners Sur',            'G88345673', '910000203', 'Calle Madrid 45',                 '28901', 'Getafe',     'Madrid'),
    ('asfalto.crew@correo.test',     'equipo',     'Asfalto Crew',                 NULL,               '965000204', 'Rambla de Méndez Núñez 30',       '03002', 'Alicante',   'Alicante')
) AS c(email, tipo, razon_social, nif, telefono, direccion, cp, ciudad, provincia)
JOIN usuarios us ON lower(us.email) = lower(c.email)
ORDER BY us.id;

-- -----------------------------------------------------------------------------
-- Auditoría: alta de cada cliente (sin datos sensibles en 'detalle')
-- -----------------------------------------------------------------------------
INSERT INTO auditoria (usuario_id, tipo_evento, entidad, entidad_id, resultado, detalle, fecha)
SELECT u.id, 'usuario.registrar', 'usuario', u.id, 'exito',
       jsonb_build_object('rol', r.codigo, 'tipo_cliente', c.tipo_cliente),
       u.fecha_creacion
FROM usuarios u
JOIN roles r    ON r.id = u.rol_id
JOIN clientes c ON c.usuario_id = u.id;

-- -----------------------------------------------------------------------------
-- Consultas del formulario de contacto (visitantes sin cuenta)
-- -----------------------------------------------------------------------------
INSERT INTO consultas (nombre, email, asunto, mensaje, estado, fecha_consulta) VALUES
    ('Álvaro Muñoz',  'alvaro.munoz@correo.test', 'Tallas de Air Jordan 1',
     '¿Las Air Jordan 1 High OG tallan igual que las Air Force 1? Uso un 43.',
     'respondida', '2026-09-14 11:20+02'),
    ('Club Hockey Meseta', 'hockey.meseta@correo.test', 'Pedido para un equipo',
     'Somos 18 jugadores y queremos Air Force 1 con el número de cada uno. ¿Hacéis descuento por volumen?',
     'pendiente', '2026-11-03 17:45+01'),
    ('Carmen Ríos',   'carmen.rios@correo.test',  'Cambio de talla',
     'Me llegaron unas Dunk Low en 39 y necesito un 40. ¿Cómo hago el cambio?',
     'cerrada', '2026-08-02 09:05+02');

COMMIT;
