-- =============================================================================
-- KALMA SNKRS · Sneaker Factory
-- schema.sql · Esquema relacional de PostgreSQL
--
-- compose.yaml lo monta como /docker-entrypoint-initdb.d/01_schema.sql y
-- Postgres lo ejecuta solo la primera vez que se crea el volumen de datos.
-- Para empezar de cero: docker compose down -v && docker compose up -d
--
-- Decisiones generales:
--   * Las claves primarias usan GENERATED ALWAYS AS IDENTITY (estándar SQL)
--     en lugar de SERIAL, para que nadie pueda forzar un id a mano.
--   * Las fechas son TIMESTAMPTZ y se guardan en UTC, así no hay problemas
--     con la zona horaria del servidor.
--   * Los estados y tipos se validan con CHECK. Las reglas de negocio
--     (transiciones de estado, permisos) se aplican en los servicios PHP.
-- =============================================================================

BEGIN;

-- -----------------------------------------------------------------------------
-- Función común: actualiza fecha_actualizacion en cada UPDATE
-- -----------------------------------------------------------------------------
CREATE FUNCTION fn_tocar_fecha_actualizacion() RETURNS trigger AS $$
BEGIN
    NEW.fecha_actualizacion := now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;


-- =============================================================================
-- 1. USUARIOS, ROLES Y CLIENTES
-- =============================================================================

-- Roles del sistema. Lo que puede hacer cada rol (la matriz de permisos)
-- se define en el middleware PHP. Aquí solo guardamos qué roles existen.
CREATE TABLE roles (
    id          INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    codigo      VARCHAR(20)  NOT NULL UNIQUE
                CHECK (codigo ~ '^[a-z_]+$'),
    nombre      VARCHAR(50)  NOT NULL,
    descripcion TEXT
);

-- Cuenta de acceso (autenticación). La usan clientes y personal interno.
CREATE TABLE usuarios (
    id                  INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    rol_id              INTEGER      NOT NULL REFERENCES roles(id),
    nombre              VARCHAR(100) NOT NULL,
    apellidos           VARCHAR(150) NOT NULL,
    email               VARCHAR(150) NOT NULL
                        CHECK (email ~* '^[^@\s]+@[^@\s]+\.[^@\s]+$'),
    password_hash       VARCHAR(255) NOT NULL,   -- password_hash() de PHP (bcrypt/argon2)
    activo              BOOLEAN      NOT NULL DEFAULT true,
    ultimo_acceso       TIMESTAMPTZ,
    fecha_creacion      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    fecha_actualizacion TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- El email es único sin distinguir mayúsculas (Ana@x.com = ana@x.com)
CREATE UNIQUE INDEX uq_usuarios_email ON usuarios (lower(email));
CREATE INDEX ix_usuarios_rol ON usuarios (rol_id);

CREATE TRIGGER trg_usuarios_actualizacion
    BEFORE UPDATE ON usuarios
    FOR EACH ROW EXECUTE FUNCTION fn_tocar_fecha_actualizacion();

-- Perfil comercial (1:1 con usuarios). Solo lo tienen los usuarios con rol
-- cliente; el personal de almacén y los admin no necesitan dirección ni NIF.
CREATE TABLE clientes (
    id            INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    usuario_id    INTEGER      NOT NULL UNIQUE
                  REFERENCES usuarios(id) ON DELETE CASCADE,
    tipo_cliente  VARCHAR(20)  NOT NULL DEFAULT 'particular'
                  CHECK (tipo_cliente IN ('particular', 'club', 'equipo')),
    razon_social  VARCHAR(150),            -- nombre del club o equipo
    nif           VARCHAR(15)  UNIQUE,
    telefono      VARCHAR(20),
    direccion     VARCHAR(200),
    codigo_postal VARCHAR(10)  CHECK (codigo_postal ~ '^[0-9]{5}$'),
    ciudad        VARCHAR(100),
    provincia     VARCHAR(100),

    -- Un club o equipo tiene que indicar su nombre
    CONSTRAINT ck_clientes_razon_social
        CHECK (tipo_cliente = 'particular' OR razon_social IS NOT NULL)
);


-- =============================================================================
-- 2. CATÁLOGO
-- =============================================================================

CREATE TABLE categorias (
    id          INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nombre      VARCHAR(100) NOT NULL UNIQUE,
    descripcion TEXT
);

-- Marcas en tabla propia: así se evita repetir texto en cada producto
-- y se puede filtrar el catálogo por marca.
CREATE TABLE marcas (
    id     INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    pais   VARCHAR(60)
);

-- Color principal de cada producto (sirve para filtrar el catálogo por color)
CREATE TABLE colores (
    id         INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nombre     VARCHAR(50) NOT NULL UNIQUE,
    codigo_hex VARCHAR(7)  NOT NULL CHECK (codigo_hex ~ '^#[0-9A-Fa-f]{6}$')
);

-- Producto = modelo + combinación de colores (colorway), igual que en Nike.com:
-- "Air Force 1 '07 White/White" y "Air Force 1 '07 Black/Black" son dos
-- productos distintos, cada uno con su código de marca, su precio y su foto.
--
-- codigo_marca: código de estilo del fabricante (style code), ej. CW2288-111
--   CW2288 = modelo (Air Force 1 '07)  ·  111 = combinación de colores (White/White)
--   Es un dato real: va impreso en la etiqueta de la caja y en la lengüeta.
--   NO es un RFID. Un RFID (EPC) identifica tallas o unidades y la marca no lo publica.
--   Los colores de un mismo modelo comparten prefijo (CW2288-*), así que se pueden
--   agrupar para mostrar "otros colores".
CREATE TABLE productos (
    id                  INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    categoria_id        INTEGER       NOT NULL REFERENCES categorias(id),
    marca_id            INTEGER       NOT NULL REFERENCES marcas(id),
    color_id            INTEGER       NOT NULL REFERENCES colores(id),  -- color principal
    nombre              VARCHAR(150)  NOT NULL,                         -- ej. Nike Air Force 1 '07
    colorway            VARCHAR(100)  NOT NULL,                         -- colores según la marca, ej. White/White
    codigo_marca        VARCHAR(10)   NOT NULL
                        CHECK (codigo_marca ~ '^[A-Z0-9]{6}-[0-9]{3}$'),
    slug                VARCHAR(160)  NOT NULL UNIQUE
                        CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
    descripcion         TEXT,
    precio              NUMERIC(10,2) NOT NULL CHECK (precio > 0),      -- igual para todas las tallas
    personalizable      BOOLEAN       NOT NULL DEFAULT false,
    imagen              VARCHAR(255),  -- ruta relativa en public/img (pendiente de rediseño en el front)
    activo              BOOLEAN       NOT NULL DEFAULT true,
    fecha_creacion      TIMESTAMPTZ   NOT NULL DEFAULT now(),
    fecha_actualizacion TIMESTAMPTZ   NOT NULL DEFAULT now(),

    -- Único dentro de cada marca: otra marca podría usar un código con el mismo formato
    CONSTRAINT uq_productos_codigo_marca UNIQUE (marca_id, codigo_marca)
);

CREATE INDEX ix_productos_categoria ON productos (categoria_id);
CREATE INDEX ix_productos_marca     ON productos (marca_id);
CREATE INDEX ix_productos_color     ON productos (color_id);

CREATE TRIGGER trg_productos_actualizacion
    BEFORE UPDATE ON productos
    FOR EACH ROW EXECUTE FUNCTION fn_tocar_fecha_actualizacion();

-- Variante = producto + talla. Es lo que se vende y lo que tiene stock.
--
-- rfid_tienda: etiqueta RFID que KALMA pone en almacén a cada talla.
--   Formato EPC-96 en hexadecimal (24 caracteres). Es UNIQUE en toda la tabla:
--   al leer una etiqueta, la variante queda identificada sin ambigüedad.
CREATE TABLE variantes (
    id                  INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    producto_id         INTEGER      NOT NULL REFERENCES productos(id),
    talla               NUMERIC(3,1) NOT NULL
                        CHECK (talla BETWEEN 34 AND 50 AND talla * 2 = trunc(talla * 2)),  -- admite medias tallas (42.5)
    stock               INTEGER      NOT NULL DEFAULT 0 CHECK (stock >= 0),
    stock_minimo        INTEGER      NOT NULL DEFAULT 3 CHECK (stock_minimo >= 0),
    rfid_tienda         VARCHAR(24)  NOT NULL UNIQUE CHECK (rfid_tienda ~ '^[0-9A-F]{24}$'),
    activo              BOOLEAN      NOT NULL DEFAULT true,
    fecha_actualizacion TIMESTAMPTZ  NOT NULL DEFAULT now(),

    CONSTRAINT uq_variantes_producto_talla UNIQUE (producto_id, talla)
);

CREATE TRIGGER trg_variantes_actualizacion
    BEFORE UPDATE ON variantes
    FOR EACH ROW EXECUTE FUNCTION fn_tocar_fecha_actualizacion();

-- Catálogo de opciones de personalización que se pueden elegir al pedir
-- (color de suela, dorsal, logo del club...). El recargo se suma al precio.
CREATE TABLE opciones_personalizacion (
    id             INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tipo           VARCHAR(30)   NOT NULL
                   CHECK (tipo IN ('color_suela', 'color_cordones', 'texto', 'dorsal', 'logo')),
    nombre         VARCHAR(100)  NOT NULL,
    requiere_texto BOOLEAN       NOT NULL DEFAULT false,   -- true para texto/dorsal: el cliente escribe un valor
    recargo        NUMERIC(10,2) NOT NULL DEFAULT 0 CHECK (recargo >= 0),
    activo         BOOLEAN       NOT NULL DEFAULT true,

    CONSTRAINT uq_opciones_tipo_nombre UNIQUE (tipo, nombre)
);


-- =============================================================================
-- 3. PEDIDOS
-- =============================================================================

-- Flujo de estados (lo valida el servicio PHP):
--   pendiente -> confirmado -> preparacion -> enviado -> entregado
--   pendiente | confirmado -> cancelado   (el stock se repone)
CREATE TABLE pedidos (
    id                  INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    codigo              VARCHAR(20)   NOT NULL UNIQUE,   -- código visible, ej. SF-2026-000001
    cliente_id          INTEGER       NOT NULL REFERENCES clientes(id),
    estado              VARCHAR(20)   NOT NULL DEFAULT 'pendiente'
                        CHECK (estado IN ('pendiente', 'confirmado', 'preparacion',
                                          'enviado', 'entregado', 'cancelado')),
    personalizado       BOOLEAN       NOT NULL DEFAULT false,
    total               NUMERIC(10,2) NOT NULL DEFAULT 0 CHECK (total >= 0),
    -- Copia de la dirección en el momento de pedir: si el cliente la cambia
    -- después, el pedido mantiene a dónde se envió de verdad.
    direccion_envio     VARCHAR(400)  NOT NULL,
    codigo_seguimiento  VARCHAR(50)   UNIQUE,            -- lo da la empresa de transporte al enviar
    notas               VARCHAR(500),
    fecha_creacion      TIMESTAMPTZ   NOT NULL DEFAULT now(),
    fecha_actualizacion TIMESTAMPTZ   NOT NULL DEFAULT now()
);

CREATE INDEX ix_pedidos_cliente ON pedidos (cliente_id);
CREATE INDEX ix_pedidos_estado  ON pedidos (estado);

CREATE TRIGGER trg_pedidos_actualizacion
    BEFORE UPDATE ON pedidos
    FOR EACH ROW EXECUTE FUNCTION fn_tocar_fecha_actualizacion();

-- Líneas del pedido. Guardan una copia de los datos del producto (nombre,
-- talla, color y precio aplicado) para que el pedido no cambie aunque luego
-- se modifique el catálogo.
CREATE TABLE lineas_pedido (
    id                      INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    pedido_id               INTEGER       NOT NULL REFERENCES pedidos(id) ON DELETE CASCADE,
    variante_id             INTEGER       NOT NULL REFERENCES variantes(id),
    nombre_producto         VARCHAR(150)  NOT NULL,
    talla                   NUMERIC(3,1)  NOT NULL,
    color                   VARCHAR(100)  NOT NULL,   -- colorway del producto en el momento del pedido
    cantidad                INTEGER       NOT NULL CHECK (cantidad BETWEEN 1 AND 20),
    precio_unitario         NUMERIC(10,2) NOT NULL CHECK (precio_unitario >= 0),
    recargo_personalizacion NUMERIC(10,2) NOT NULL DEFAULT 0 CHECK (recargo_personalizacion >= 0),
    -- Importe calculado por la propia BD: nunca se desincroniza
    importe                 NUMERIC(10,2) GENERATED ALWAYS AS
                            (cantidad * (precio_unitario + recargo_personalizacion)) STORED,

    -- La misma variante no se repite en un pedido (se suma la cantidad)
    CONSTRAINT uq_lineas_pedido_variante UNIQUE (pedido_id, variante_id)
);

CREATE INDEX ix_lineas_variante ON lineas_pedido (variante_id);

-- Opciones de personalización elegidas en cada línea. Se copia el recargo
-- que se aplicó, por si el catálogo de opciones cambia más adelante.
CREATE TABLE linea_personalizacion (
    id               INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    linea_id         INTEGER       NOT NULL REFERENCES lineas_pedido(id) ON DELETE CASCADE,
    opcion_id        INTEGER       NOT NULL REFERENCES opciones_personalizacion(id),
    texto            VARCHAR(30),           -- valor libre: nombre o dorsal
    recargo_aplicado NUMERIC(10,2) NOT NULL DEFAULT 0 CHECK (recargo_aplicado >= 0),

    CONSTRAINT uq_linea_opcion UNIQUE (linea_id, opcion_id)
);

-- Historial de transiciones de estado: quién cambió qué y cuándo.
-- La primera fila de cada pedido tiene estado_anterior NULL (creación).
CREATE TABLE historial_estados_pedido (
    id              INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    pedido_id       INTEGER     NOT NULL REFERENCES pedidos(id) ON DELETE CASCADE,
    estado_anterior VARCHAR(20),
    estado_nuevo    VARCHAR(20) NOT NULL,
    usuario_id      INTEGER     REFERENCES usuarios(id) ON DELETE SET NULL,
    comentario      VARCHAR(255),
    fecha           TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX ix_historial_pedido ON historial_estados_pedido (pedido_id, fecha);

-- Movimientos de stock: cada cambio de stock deja un rastro (cantidad con
-- signo: + entrada, - salida) y el stock que quedó después del cambio.
CREATE TABLE movimientos_stock (
    id               INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    variante_id      INTEGER     NOT NULL REFERENCES variantes(id),
    tipo             VARCHAR(20) NOT NULL
                     CHECK (tipo IN ('entrada', 'venta', 'cancelacion', 'ajuste')),
    cantidad         INTEGER     NOT NULL CHECK (cantidad <> 0),
    stock_resultante INTEGER     NOT NULL CHECK (stock_resultante >= 0),
    pedido_id        INTEGER     REFERENCES pedidos(id) ON DELETE SET NULL,
    usuario_id       INTEGER     REFERENCES usuarios(id) ON DELETE SET NULL,
    motivo           VARCHAR(255),
    fecha            TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX ix_movimientos_variante ON movimientos_stock (variante_id, fecha);


-- =============================================================================
-- 4. AUDITORÍA Y CONTACTO
-- =============================================================================

-- Registro de eventos de negocio: quién, cuándo, qué tipo, sobre qué entidad
-- y con qué resultado. NUNCA se guardan contraseñas ni tokens en 'detalle'.
CREATE TABLE auditoria (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    usuario_id  INTEGER     REFERENCES usuarios(id) ON DELETE SET NULL,  -- NULL = anónimo o usuario borrado
    tipo_evento VARCHAR(50) NOT NULL,    -- ej. pedido.crear, pedido.cancelar, stock.ajustar, auth.login
    entidad     VARCHAR(50) NOT NULL,    -- ej. pedido, variante, usuario
    entidad_id  INTEGER,
    resultado   VARCHAR(10) NOT NULL CHECK (resultado IN ('exito', 'error', 'denegado')),
    detalle     JSONB,
    ip          INET,
    fecha       TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX ix_auditoria_fecha   ON auditoria (fecha DESC);
CREATE INDEX ix_auditoria_entidad ON auditoria (entidad, entidad_id);

-- Formulario de contacto público (puede enviarlo un visitante anónimo,
-- por eso no tiene FK a usuarios).
CREATE TABLE consultas (
    id             INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nombre         VARCHAR(100)  NOT NULL,
    email          VARCHAR(150)  NOT NULL CHECK (email ~* '^[^@\s]+@[^@\s]+\.[^@\s]+$'),
    asunto         VARCHAR(150)  NOT NULL,
    mensaje        VARCHAR(2000) NOT NULL,
    estado         VARCHAR(20)   NOT NULL DEFAULT 'pendiente'
                   CHECK (estado IN ('pendiente', 'respondida', 'cerrada')),
    fecha_consulta TIMESTAMPTZ   NOT NULL DEFAULT now()
);

COMMIT;

