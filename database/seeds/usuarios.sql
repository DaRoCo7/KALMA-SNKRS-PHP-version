-- =============================================================================
-- KALMA SNKRS · Datos semilla 1/3: roles, usuarios y clientes
--
-- compose.yaml lo monta como /docker-entrypoint-initdb.d/02_usuarios.sql
-- (se ejecuta después del esquema y antes del catálogo).
--
-- Todas las personas son FICTICIAS. Los emails usan el dominio reservado
-- .test para que nunca puedan ser direcciones reales.
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
    -- Clientes
    ('cliente', 'Lucía',   'Fernández Ruiz',   'cliente@kalma.test',              '$2y$10$mW11FhHfWQQu4rQPTW125eADUkAPAyCm1u.Uwm7BPgSrv/rdfv1WK', true,  '2026-03-04 18:12+01'),
    ('cliente', 'Marcos',  'Gil Navarro',      'marcos.gil@correo.test',          '$2y$10$Bkg1VYPvJMfvtGZaLQ15DOZ0oN294/PhvvbGyaMhCaaBCf.iGCw3C', true,  '2026-03-15 21:40+01'),
    ('cliente', 'Paula',   'Romero Sanz',      'paula.romero@correo.test',        '$2y$10$0vBL3DnLtPb9Zr6twM0kWOUjsuyDoDpraVQCwPWSjwM7pHCygGGfi', true,  '2026-04-02 12:05+02'),
    ('cliente', 'Javier',  'Ortega Molina',    'javier.ortega@correo.test',       '$2y$10$cK98041.MaDhoST5lLN3jOj18LYTVlIXMTtjXSE6D73L6X7uVkQbW', true,  '2026-04-19 17:33+02'),
    ('cliente', 'Irene',   'Castro Delgado',   'irene.castro@correo.test',        '$2y$10$PIh/wza8yltw9zyrY0qCzepnjgo.uCAQwZA/tpTLnNZeDZHNy9jY2', true,  '2026-05-06 10:47+02'),
    ('cliente', 'Daniel',  'Vidal Herrera',    'daniel.vidal@correo.test',        '$2y$10$3w7evzflKBj1.RWj.VDyo.QOHbKsLYFKRHPwD3h5yLkzsN0KzOsqK', true,  '2026-05-23 19:20+02'),
    ('cliente', 'Sara',    'Medina Ibáñez',    'sara.medina@correo.test',         '$2y$10$FdukyFg85bdIvYOa32LP4ucO3mYcJ/8HFBeuW64fi3qbFSRf5Eyy6', true,  '2026-06-08 16:02+02'),
    ('cliente', 'Hugo',    'Serrano Prieto',   'hugo.serrano@correo.test',        '$2y$10$l1lXKtXc1wQaeOFgsQeFteaPNZCPGYnPLAJ2yA.Dg7WNzZzURQLKC', true,  '2026-06-21 22:15+02'),
    ('cliente', 'Tomás',   'Vera Aguilar',     'tomas.vera@correo.test',          '$2y$10$XO2fTlF2ERAPHIyLCXnCMOEMvR6LX3pxCt4.HfhoqGff.mzpYMF4e', false, '2026-04-11 11:11+02'),
    ('cliente', 'Andrés',  'Lozano Rey',       'andres.lozano@correo.test',       '$2y$10$Fs1MhlbcbyyCuRDgEdNzH.cCxReGf8J5oyAS759DEKE8xtDvK.GLK', true,  '2026-03-28 09:15+01'),
    ('cliente', 'Elena',   'Pascual Vega',     'elena.pascual@correo.test',       '$2y$10$KKxnSPsGEv./ef83QfL0UuTVmcIy/rXXTMMCS6r6BImrLIK5YEpS6', true,  '2026-05-12 13:30+02'),
    ('cliente', 'Rubén',   'Cano Fuentes',     'ruben.cano@correo.test',          '$2y$10$IWhBjV7R2mVupvYg83ZDn.5uHGfFvUycCvecCxfQJDQH/0jwwOEb2', true,  '2026-06-02 20:45+02'),
    ('cliente', 'Nerea',   'Blanco Soler',     'nerea.blanco@correo.test',        '$2y$10$wuEMoo.Jf2GdLhcaQKchQOq0YECelSqAQP.1/ywVkVS0mxEq9CmI2', true,  '2026-06-30 18:00+02')
) AS u(rol, nombre, apellidos, email, hash, activo, alta)
JOIN roles r ON r.codigo = u.rol
ORDER BY u.alta::timestamptz;   -- los id siguen el orden de alta

-- -----------------------------------------------------------------------------
-- Perfil de los clientes. Los DNI son ficticios pero tienen la letra de
-- control correcta, así que pasan la misma validación que hace PHP.
-- El DNI es opcional: Nerea Blanco no lo ha indicado (NULL permitido).
-- -----------------------------------------------------------------------------
INSERT INTO clientes (usuario_id, nif, telefono, direccion, codigo_postal, ciudad, provincia)
SELECT us.id, c.nif, c.telefono, c.direccion, c.cp, c.ciudad, c.provincia
FROM (VALUES
    ('cliente@kalma.test',        '50112233V', '600000101', 'Calle Mayor 12, 2º A',            '28013', 'Madrid',     'Madrid'),
    ('marcos.gil@correo.test',    '20334455V', '600000102', 'Calle de Colón 8, 4º',            '46004', 'Valencia',   'Valencia'),
    ('paula.romero@correo.test',  '28445566V', '600000103', 'Avenida de la Constitución 21',   '41001', 'Sevilla',    'Sevilla'),
    ('javier.ortega@correo.test', '17556677H', '600000104', 'Paseo de la Independencia 5, 1º', '50001', 'Zaragoza',   'Zaragoza'),
    ('irene.castro@correo.test',  '25667788H', '600000105', 'Calle Larios 3, 3º B',            '29005', 'Málaga',     'Málaga'),
    ('daniel.vidal@correo.test',  '46778899G', '600000106', 'Carrer de Pelai 14, 2º 1ª',       '08001', 'Barcelona',  'Barcelona'),
    ('sara.medina@correo.test',   '16889900B', '600000107', 'Gran Vía 40, 5º D',               '48011', 'Bilbao',     'Bizkaia'),
    ('hugo.serrano@correo.test',  '12990011W', '600000108', 'Calle Santiago 9, 1º',            '47001', 'Valladolid', 'Valladolid'),
    ('tomas.vera@correo.test',    '48101122A', '600000109', 'Calle Real 2',                    '30001', 'Murcia',     'Murcia'),
    ('andres.lozano@correo.test', '51223344K', '600000110', 'Calle de Alcalá 150, 3º C',       '28028', 'Madrid',     'Madrid'),
    ('elena.pascual@correo.test', '23667788Y', '600000111', 'Avenida de la Libertad 22, 1º',   '30009', 'Murcia',     'Murcia'),
    ('ruben.cano@correo.test',    '30889911Z', '600000112', 'Calle Madrid 45, 2º B',           '28901', 'Getafe',     'Madrid'),
    ('nerea.blanco@correo.test',  NULL,        '600000113', 'Rambla de Méndez Núñez 30, 4º',   '03002', 'Alicante',   'Alicante')
) AS c(email, nif, telefono, direccion, cp, ciudad, provincia)
JOIN usuarios us ON lower(us.email) = lower(c.email)
ORDER BY us.id;

-- -----------------------------------------------------------------------------
-- Auditoría: alta de cada cliente (sin datos sensibles en 'detalle')
-- -----------------------------------------------------------------------------
INSERT INTO auditoria (usuario_id, tipo_evento, entidad, entidad_id, resultado, detalle, fecha)
SELECT u.id, 'usuario.registrar', 'usuario', u.id, 'exito',
       jsonb_build_object('rol', r.codigo),
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
    ('Rocío Gallardo', 'rocio.gallardo@correo.test', 'Reposición de Air Jordan 1',
     'Las Air Jordan 1 «Lost & Found» aparecen agotadas en la talla 42. ¿Vais a recibir más pares?',
     'pendiente', '2026-11-03 17:45+01'),
    ('Carmen Ríos',   'carmen.rios@correo.test',  'Cambio de talla',
     'Me llegaron unas Dunk Low en 39 y necesito un 40. ¿Cómo hago el cambio?',
     'cerrada', '2026-08-02 09:05+02');

COMMIT;
