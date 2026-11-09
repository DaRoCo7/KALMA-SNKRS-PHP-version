
-- Tabla CATEGORIAS
-- Aqui guardaremos las categorias de las zapatillas

CREATE TABLE public.categorias (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    descripcion TEXT
);


-- Tabla PRODUCTOS
-- Aqui guardaremos cada modelo de zapatilla con su marca, color y precio

CREATE TABLE public.productos (
    id SERIAL PRIMARY KEY,
    categoria_id INTEGER NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    marca VARCHAR(100) NOT NULL,
    color VARCHAR(100) NOT NULL,
    descripcion TEXT,
    precio NUMERIC(10,2) NOT NULL CHECK (precio >= 0),
    imagen_url TEXT NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT true,
    fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_productos_categorias
        FOREIGN KEY (categoria_id)
        REFERENCES public.categorias(id)
        ON DELETE RESTRICT
);

CREATE INDEX idx_productos_categoria
    ON public.productos (categoria_id);


-- Tabla VARIANTES
-- Aqui guardaremos las tallas y el stock de cada zapatilla

CREATE TABLE public.variantes (
    id SERIAL PRIMARY KEY,
    producto_id INTEGER NOT NULL,
    talla INTEGER NOT NULL CHECK (talla BETWEEN 20 AND 55),
    stock INTEGER NOT NULL DEFAULT 0 CHECK (stock >= 0),

    CONSTRAINT fk_variantes_productos
        FOREIGN KEY (producto_id)
        REFERENCES public.productos(id)
        ON DELETE RESTRICT,

    CONSTRAINT uq_producto_talla
        UNIQUE (producto_id, talla)
);

CREATE INDEX idx_variantes_producto
    ON public.variantes (producto_id);


-- Tabla USUARIOS
-- Aqui guardaremos los datos de los usuarios de la aplicacion

CREATE TABLE public.usuarios (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    apellidos VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    rol VARCHAR(20) NOT NULL DEFAULT 'cliente'
        CHECK (rol IN ('cliente', 'admin', 'almacen')),
    activo BOOLEAN NOT NULL DEFAULT true,
    fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);


-- Tabla SESIONES
-- Guardaremos el hash SHA-256 del token de sesion, nunca el token original

CREATE TABLE public.sesiones (
    id SERIAL PRIMARY KEY,
    usuario_id INTEGER NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    fecha_creacion TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_caducidad TIMESTAMPTZ NOT NULL,
    fecha_revocacion TIMESTAMPTZ,

    CONSTRAINT fk_sesiones_usuarios
        FOREIGN KEY (usuario_id)
        REFERENCES public.usuarios(id)
        ON DELETE CASCADE,

    CONSTRAINT uq_sesiones_token_hash
        UNIQUE (token_hash),

    CONSTRAINT ck_sesiones_token_hash
        CHECK (token_hash ~ '^[a-f0-9]{64}$'),

    CONSTRAINT ck_sesiones_caducidad
        CHECK (fecha_caducidad > fecha_creacion),

    CONSTRAINT ck_sesiones_revocacion
        CHECK (
            fecha_revocacion IS NULL
            OR fecha_revocacion >= fecha_creacion
        )
);

CREATE INDEX idx_sesiones_usuario
    ON public.sesiones (usuario_id);


-- Tabla PEDIDOS
-- Aqui guardaremos los pedidos realizados por los usuarios

CREATE TABLE public.pedidos (
    id SERIAL PRIMARY KEY,
    usuario_id INTEGER NOT NULL,
    codigo_seguimiento VARCHAR(100) UNIQUE,
    estado VARCHAR(30) NOT NULL DEFAULT 'pendiente'
        CHECK (
            estado IN (
                'pendiente',
                'confirmado',
                'preparacion',
                'enviado',
                'entregado',
                'cancelado'
            )
        ),
    total NUMERIC(12,2) NOT NULL CHECK (total >= 0),
    direccion_envio TEXT,
    codigo_postal VARCHAR(15),
    localidad VARCHAR(100),
    fecha_pedido TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_pedidos_usuarios
        FOREIGN KEY (usuario_id)
        REFERENCES public.usuarios(id)
        ON DELETE RESTRICT
);

CREATE INDEX idx_pedidos_usuario
    ON public.pedidos (usuario_id);

CREATE INDEX idx_pedidos_estado
    ON public.pedidos (estado);


-- Tabla DETALLE_PEDIDO
-- Aqui guardaremos los productos que contiene cada pedido

CREATE TABLE public.detalle_pedido (
    id SERIAL PRIMARY KEY,
    pedido_id INTEGER NOT NULL,
    variante_id INTEGER NOT NULL,
    cantidad INTEGER NOT NULL CHECK (cantidad > 0),
    precio_unitario NUMERIC(10,2) NOT NULL CHECK (precio_unitario >= 0),
    personalizacion JSONB,

    CONSTRAINT fk_detalle_pedido_pedidos
        FOREIGN KEY (pedido_id)
        REFERENCES public.pedidos(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_detalle_pedido_variantes
        FOREIGN KEY (variante_id)
        REFERENCES public.variantes(id)
        ON DELETE RESTRICT,

    CONSTRAINT ck_detalle_personalizacion
        CHECK (
            personalizacion IS NULL
            OR jsonb_typeof(personalizacion) = 'object'
        )
);

CREATE INDEX idx_detalle_pedido
    ON public.detalle_pedido (pedido_id);

CREATE INDEX idx_detalle_variante
    ON public.detalle_pedido (variante_id);


-- Tabla HISTORIAL_PEDIDOS
-- Aqui guardaremos los cambios de estado de los pedidos

CREATE TABLE public.historial_pedidos (
    id SERIAL PRIMARY KEY,
    pedido_id INTEGER NOT NULL,
    usuario_id INTEGER,
    estado_anterior VARCHAR(30),
    estado_nuevo VARCHAR(30) NOT NULL,
    fecha_cambio TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    observaciones TEXT,

    CONSTRAINT fk_historial_pedidos
        FOREIGN KEY (pedido_id)
        REFERENCES public.pedidos(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_historial_usuarios
        FOREIGN KEY (usuario_id)
        REFERENCES public.usuarios(id)
        ON DELETE SET NULL,

    CONSTRAINT ck_historial_estado_anterior
        CHECK (
            estado_anterior IS NULL
            OR estado_anterior IN (
                'pendiente',
                'confirmado',
                'preparacion',
                'enviado',
                'entregado',
                'cancelado'
            )
        ),

    CONSTRAINT ck_historial_estado_nuevo
        CHECK (
            estado_nuevo IN (
                'pendiente',
                'confirmado',
                'preparacion',
                'enviado',
                'entregado',
                'cancelado'
            )
        )
);

CREATE INDEX idx_historial_pedido
    ON public.historial_pedidos (pedido_id);


-- Tabla CONSULTAS
-- Aqui guardaremos los mensajes enviados por los usuarios

CREATE TABLE public.consultas (
    id SERIAL PRIMARY KEY,
    producto_id INTEGER,
    usuario_id INTEGER,
    referencia_hash VARCHAR(64),
    nombre VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    asunto VARCHAR(150) NOT NULL,
    mensaje TEXT NOT NULL,
    estado VARCHAR(30) NOT NULL DEFAULT 'pendiente'
        CHECK (estado IN ('pendiente', 'respondida', 'cerrada')),
    fecha_consulta TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_consultas_productos
        FOREIGN KEY (producto_id)
        REFERENCES public.productos(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_consultas_usuarios
        FOREIGN KEY (usuario_id)
        REFERENCES public.usuarios(id)
        ON DELETE SET NULL,

    CONSTRAINT uq_consultas_referencia_hash
        UNIQUE (referencia_hash),

    CONSTRAINT ck_consultas_referencia_hash
        CHECK (
            referencia_hash IS NULL
            OR referencia_hash ~ '^[a-f0-9]{64}$'
        )
);

CREATE INDEX idx_consultas_usuario
    ON public.consultas (usuario_id);


-- Tabla AUDITORIA
-- Aqui guardaremos las acciones importantes realizadas en la aplicacion

CREATE TABLE public.auditoria (
    id SERIAL PRIMARY KEY,
    usuario_id INTEGER,
    accion VARCHAR(100) NOT NULL,
    tabla_afectada VARCHAR(100) NOT NULL,
    registro_id INTEGER,
    descripcion TEXT,
    resultado VARCHAR(30) NOT NULL DEFAULT 'correcto'
        CHECK (resultado IN ('correcto', 'error')),
    fecha_accion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_auditoria_usuarios
        FOREIGN KEY (usuario_id)
        REFERENCES public.usuarios(id)
        ON DELETE SET NULL
);

CREATE INDEX idx_auditoria_usuario
    ON public.auditoria (usuario_id);

CREATE INDEX idx_auditoria_fecha
    ON public.auditoria (fecha_accion);


-- Tabla RESERVAS
-- No la usarems en este proyecto, ya está incluido en el otro
