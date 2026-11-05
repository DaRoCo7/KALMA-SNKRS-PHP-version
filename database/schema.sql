CREATE DATABASE kalma_snkrs;

-- Tabla CATEGORIAS	

CREATE TABLE public.categorias (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    descripcion TEXT
);


-- info productor/zapatillas
create table public.productos (
	id SERIAL primary key, 
	categoria_id INTEGER not null,
	nombre VARCHAR(150) not null,
	marca VARCHAR(100) not  null,
	descripcion TEXT,
	activo boolean not null default true,
	fecha_creacion TIMESTAMP not null default CURRENT_TIMESTAMP,
	
	constraint fk_poductos_categorias
		FOREIGN KEY (categoria_id)
		references public.categorias(id)
);


-- Tabla variantes donde irá tallas y stock

create table public.variantes (
	id SERIAL primary key,
	producto_id INTEGER not null,
	talla INTEGER not null,
	stock INTEGER not null default 0 check (stock >=0),
	
	constraint fk_variantes_productos
	foreign key (producto_id)
	references public.productos(id),
	
	constraint uq_producto_talla
	unique (producto_id, talla)

);



-- Tabla Usuarios (los datos que va almacenar la app de cada usuario)

create table public.usuarios (
	id SERIAL primary key,
	nombre VARCHAR(100) not null,
	apellidos VARCHAR(150) not null,
	email VARCHAR(150) not null unique,
	password_hash TEXT not null,
	rol VARCHAR(20) not null default 'cliente'
		check (rol in ('cliente', 'admin', 'almacen')),
	activo BOOLEAN not null default true,
	fecha_creacion TIMESTAMP not null default CURRENT_TIMESTAMP
);





-- tabla pedidos (usuario, seguimiento, estado, etc)

create table public.pedidos (
	id SERIAL primary key,
	usuario_id INTEGER not null,
	codigo_seguimiento VARCHAR(100) unique,
	estado VARCHAR(30) not null,
	total NUMERIC(10,2) not null,
	fecha_pedido TIMESTAMP default CURRENT_TIMESTAMP,
	
	foreign key (usuario_id) references public.usuarios(id)
	
);

-- tabla detalles pedido

create table public.detalle_pedido (
	id SERIAL primary key,
	pedido_id INTEGER not null,
	variante_id INTEGER not null,
	cantidad INTEGER not null,
	precio_unitario NUMERIC(10,2) not null,
	
	FOREIGN KEY (pedido_id) references public.pedidos(id),
	FOREIGN KEY (variante_id) references public.variantes(id)
	
);


-- tabla consultas (datos usuarios en formulario)

create table public.consultas (
	id SERIAL primary key,
	nombre VARCHAR(100) not null,
	email VARCHAR(150) not null,
	asunto VARCHAR(150) not null,
	mensaje TEXT not null,
	estado VARCHAR(30) default 'pendiente',
	fecha_consulta TIMESTAMP default current_timestamp
);


-- Tabla auditoria (acciones importantes)

create table public.auditoria (
	id SERIAL primary key,
	usuario_id INTEGER,
	accion VARCHAR(100) not null,
	tabla_afectada VARCHAR(100) not null,
	descripcion TEXT,
	fecha_accion TIMESTAMP default current_timestamp,
	
	foreign key (usuario_id) references public.usuarios(id)
	on delete set null  -- Usaremos esto para que por mucho que se borre usuario no se elime los registros en auditoria
);
