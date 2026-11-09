# Diagrama entidad/relación · KALMA SNKRS

Modelo de datos de [`database/schema.sql`](../database/schema.sql): PostgreSQL 17 con 16 tablas.

```mermaid
erDiagram
    %% Línea continua (--): entidad dependiente, se borra en cascada con su padre.
    %% Línea discontinua (..): referencia que impide el borrado o pasa a NULL.

    %% ---------- Usuarios, roles y clientes ----------
    roles ||..o{ usuarios : "asigna"
    usuarios ||--o| clientes : "tiene perfil"
    clientes ||..o{ pedidos : "realiza"

    %% ---------- Catálogo ----------
    categorias ||..o{ productos : "clasifica"
    marcas ||..o{ productos : "fabrica"
    colores ||..o{ productos : "color principal"
    productos ||..o{ variantes : "se vende por tallas"

    %% ---------- Pedidos ----------
    pedidos ||--o{ lineas_pedido : "contiene"
    variantes ||..o{ lineas_pedido : "se pide en"
    lineas_pedido ||--o{ linea_personalizacion : "lleva"
    opciones_personalizacion ||..o{ linea_personalizacion : "elegida en"
    pedidos ||--o{ historial_estados_pedido : "registra"
    usuarios |o..o{ historial_estados_pedido : "cambia estado"

    %% ---------- Stock y auditoría ----------
    variantes ||..o{ movimientos_stock : "mueve"
    pedidos |o..o{ movimientos_stock : "origina"
    usuarios |o..o{ movimientos_stock : "registra"
    usuarios |o..o{ auditoria : "genera"

    roles {
        int id PK
        varchar codigo UK "admin, almacen, cliente"
        varchar nombre
        text descripcion
    }
    usuarios {
        int id PK
        int rol_id FK
        varchar nombre
        varchar apellidos
        varchar email UK "único sin distinguir mayúsculas"
        varchar password_hash "bcrypt o argon2"
        bool activo
        timestamptz ultimo_acceso
        timestamptz fecha_creacion
        timestamptz fecha_actualizacion
    }
    clientes {
        int id PK
        int usuario_id FK, UK "1 a 1 con usuarios"
        varchar tipo_cliente "particular, club o equipo"
        varchar razon_social "obligatoria si es club o equipo"
        varchar nif UK
        varchar telefono
        varchar direccion
        varchar codigo_postal
        varchar ciudad
        varchar provincia
    }
    categorias {
        int id PK
        varchar nombre UK
        text descripcion
    }
    marcas {
        int id PK
        varchar nombre UK
        varchar pais
    }
    colores {
        int id PK
        varchar nombre UK
        varchar codigo_hex "color en hexadecimal"
    }
    productos {
        int id PK
        int categoria_id FK
        int marca_id FK
        int color_id FK
        varchar nombre
        varchar colorway "ej. White/White"
        varchar codigo_marca UK "style code real, único por marca"
        varchar slug UK
        text descripcion
        numeric precio
        bool personalizable
        varchar imagen
        bool activo
        timestamptz fecha_creacion
        timestamptz fecha_actualizacion
    }
    variantes {
        int id PK
        int producto_id FK
        numeric talla "única por producto, admite 42.5"
        int stock
        int stock_minimo
        varchar rfid_tienda UK "EPC-96 de KALMA, 24 hex"
        bool activo
        timestamptz fecha_actualizacion
    }
    opciones_personalizacion {
        int id PK
        varchar tipo "suela, cordones, texto, dorsal, logo"
        varchar nombre "único junto con tipo"
        bool requiere_texto
        numeric recargo
        bool activo
    }
    pedidos {
        int id PK
        varchar codigo UK "ej. SF-2026-000001"
        int cliente_id FK
        varchar estado "pendiente a entregado, o cancelado"
        bool personalizado
        numeric total
        varchar direccion_envio "copia al hacer el pedido"
        varchar codigo_seguimiento UK
        varchar notas
        timestamptz fecha_creacion
        timestamptz fecha_actualizacion
    }
    lineas_pedido {
        int id PK
        int pedido_id FK
        int variante_id FK "única dentro del pedido"
        varchar nombre_producto "copia"
        numeric talla "copia"
        varchar color "copia del colorway"
        int cantidad "de 1 a 20"
        numeric precio_unitario "precio aplicado"
        numeric recargo_personalizacion
        numeric importe "columna generada"
    }
    linea_personalizacion {
        int id PK
        int linea_id FK
        int opcion_id FK "única dentro de la línea"
        varchar texto "nombre o dorsal"
        numeric recargo_aplicado
    }
    historial_estados_pedido {
        int id PK
        int pedido_id FK
        varchar estado_anterior "NULL al crear el pedido"
        varchar estado_nuevo
        int usuario_id FK
        varchar comentario
        timestamptz fecha
    }
    movimientos_stock {
        int id PK
        int variante_id FK
        varchar tipo "entrada, venta, cancelacion, ajuste"
        int cantidad "con signo"
        int stock_resultante
        int pedido_id FK
        int usuario_id FK
        varchar motivo
        timestamptz fecha
    }
    auditoria {
        bigint id PK
        int usuario_id FK "NULL si es anónimo"
        varchar tipo_evento "ej. pedido.crear"
        varchar entidad
        int entidad_id
        varchar resultado "exito, error, denegado"
        jsonb detalle "nunca contraseñas ni tokens"
        inet ip
        timestamptz fecha
    }
    consultas {
        int id PK
        varchar nombre
        varchar email
        varchar asunto
        varchar mensaje
        varchar estado "pendiente, respondida, cerrada"
        timestamptz fecha_consulta
    }
```

Leyenda: `||` exactamente uno · `|o` cero o uno · `o{` cero o muchos. La línea continua indica una entidad dependiente (`ON DELETE CASCADE`); la discontinua, una referencia.

## Relaciones

| Origen → destino | Cardinalidad | FK | ON DELETE |
|---|---|---|---|
| usuarios → roles | N : 1 | `usuarios.rol_id` | NO ACTION (impide borrar) |
| clientes → usuarios | 0..1 : 1 | `clientes.usuario_id` (UNIQUE) | CASCADE |
| pedidos → clientes | N : 1 | `pedidos.cliente_id` | NO ACTION |
| productos → categorias | N : 1 | `productos.categoria_id` | NO ACTION |
| productos → marcas | N : 1 | `productos.marca_id` | NO ACTION |
| productos → colores | N : 1 | `productos.color_id` | NO ACTION |
| variantes → productos | N : 1 | `variantes.producto_id` | NO ACTION |
| lineas_pedido → pedidos | N : 1 (el servicio exige al menos 1 línea) | `lineas_pedido.pedido_id` | CASCADE |
| lineas_pedido → variantes | N : 1 | `lineas_pedido.variante_id` | NO ACTION |
| linea_personalizacion → lineas_pedido | N : 1 | `linea_personalizacion.linea_id` | CASCADE |
| linea_personalizacion → opciones_personalizacion | N : 1 | `linea_personalizacion.opcion_id` | NO ACTION |
| historial_estados_pedido → pedidos | N : 1 | `historial_estados_pedido.pedido_id` | CASCADE |
| historial_estados_pedido → usuarios | N : 0..1 | `historial_estados_pedido.usuario_id` | SET NULL |
| movimientos_stock → variantes | N : 1 | `movimientos_stock.variante_id` | NO ACTION |
| movimientos_stock → pedidos | N : 0..1 | `movimientos_stock.pedido_id` | SET NULL |
| movimientos_stock → usuarios | N : 0..1 | `movimientos_stock.usuario_id` | SET NULL |
| auditoria → usuarios | N : 0..1 | `auditoria.usuario_id` | SET NULL |

`consultas` no tiene relaciones: la puede enviar un visitante anónimo.

## Justificación de las tablas

**Usuarios y acceso**
- **roles**: los perfiles (admin, almacen, cliente) están en una tabla con FK en lugar de un CHECK, así que añadir un rol no obliga a cambiar el esquema. La matriz de permisos está en el middleware PHP.
- **usuarios**: datos de acceso comunes a clientes y personal. Solo guarda el hash de la contraseña, y el email es único sin distinguir mayúsculas.
- **clientes**: perfil comercial 1:1 separado del acceso. Solo los clientes tienen NIF y dirección, así el personal interno no deja columnas vacías. Distingue particular, club y equipo.

**Catálogo**
- **categorias**: agrupa el catálogo (Lifestyle, Air Max, Jordan) para poder filtrar.
- **marcas**: evita repetir la marca en cada producto y permite que el código de estilo sea único por marca.
- **colores**: color principal normalizado (nombre y hex) para el filtro por color.
- **productos**: un modelo en una combinación de colores, como en Nike.com, con su código de estilo real, su precio, su foto y si admite personalización.
- **variantes**: producto + talla. Es lo que se vende y lo que tiene stock. Su `CHECK (stock >= 0)` es la última defensa contra la sobreventa. Aquí va también la etiqueta RFID de la tienda.
- **opciones_personalizacion**: catálogo de personalizaciones válidas con su recargo. El servidor valida contra esta tabla en lugar de aceptar texto libre.

**Pedidos**
- **pedidos**: cabecera del pedido: cliente, estado (con CHECK), total y una copia de la dirección de envío del momento de la compra.
- **lineas_pedido**: detalle del pedido con una copia del nombre, la talla, el color y el precio aplicado. El importe es una columna generada y la misma variante no se repite dentro de un pedido.
- **linea_personalizacion**: opciones elegidas en cada línea de un pedido personalizado, con el texto (nombre o dorsal) y el recargo que se aplicó.
- **historial_estados_pedido**: guarda cada transición de estado (estado anterior y nuevo, quién y cuándo), tal como pide el enunciado.
- **movimientos_stock**: guarda cada cambio de stock (entrada, venta, cancelación, ajuste) con el stock resultante, para poder reconstruir y auditar el inventario.

**Control**
- **auditoria**: eventos de negocio con usuario, fecha, tipo, entidad y resultado. El detalle es JSONB y nunca guarda contraseñas ni tokens.
- **consultas**: formulario de contacto público. No tiene FK porque lo puede enviar un visitante anónimo.

## Códigos de producto: `codigo_marca` no es `rfid_tienda`

| | `productos.codigo_marca` | `variantes.rfid_tienda` |
|---|---|---|
| Qué es | Código de estilo (*style code*) de Nike | Etiqueta RFID (EPC-96) de KALMA |
| Ejemplo | `CW2288-111` = modelo `CW2288` + color `111` | `4B4C4D…` (24 caracteres hex) |
| Nivel | Modelo + color (el mismo en todas las tallas) | Cada talla |
| Origen | Real, impreso en la caja y en la lengüeta | Lo genera la tienda |
| Unicidad | `UNIQUE (marca_id, codigo_marca)` | `UNIQUE` en toda la tabla |

`codigo_marca` **no es un RFID**: un EPC identifica tallas o unidades, y la marca no lo publica.
