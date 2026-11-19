# Resultados de la colección de pruebas

Resultados **esperados** (las comprobaciones de cada petición de la colección) y **obtenidos** (lo que respondió la aplicación) en una ejecución completa de [`postman/KALMA-SNKRS.postman_collection.json`](../../postman/KALMA-SNKRS.postman_collection.json).

| | |
|---|---|
| Fecha | 19/11/2026 21:42 |
| Código probado | commit `d296743` |
| Entorno | Pila Docker aislada (`proxy`, `app`, `db`) con la base de datos recién creada desde el esquema y las semillas, por HTTPS |
| Herramienta | newman 6.2.3 (la línea de comandos de Postman) |
| Pruebas (peticiones de la colección) | **176** |
| Peticiones HTTP | 294 (incluye las que lanzan los scripts para leer el token CSRF o comprobar el stock) |
| Comprobaciones | **375** |
| Comprobaciones fallidas | **0** |
| Duración | 20,7 s (respuesta media de 17 ms, máxima de 80 ms) |

Para repetirla con la aplicación en modo desarrollo (punto 9 del README):

```bash
npx newman run postman/KALMA-SNKRS.postman_collection.json \
  -e postman/KALMA-SNKRS.postman_environment.json --insecure
```

La colección deja datos nuevos (pedidos, consultas, albaranes de prueba), así que los mismos resultados se obtienen con la base de datos recién creada: `docker compose ... down -v` y volver a arrancar.

## Respuestas de error y usuarios sin permiso

70 de las 176 pruebas esperan una respuesta de error y la obtienen:

| Código | Significado | Pruebas | Nº de prueba |
|---|---|---|---|
| `400` | Datos no válidos: el detalle trae el motivo de cada campo | 26 | 6, 16, 24, 32, 34, 35, 44, 45, 51, 52, 56, 71, 79, 84, 91, 101, 102, 114, 117, 121, 124, 134, 144, 156, 157, 158 |
| `401` | Sin sesión iniciada o credenciales incorrectas | 7 | 11, 12, 28, 60, 67, 105, 140 |
| `403` | Sesión válida pero sin permiso para la operación, o token CSRF falso | 14 | 9, 25, 62, 74, 75, 127, 128, 135, 138, 150, 151, 163, 174, 175 |
| `404` | Ruta o recurso inexistente, o pedido de otro cliente (IDOR) | 11 | 3, 22, 31, 49, 54, 73, 96, 113, 118, 133, 171 |
| `405` | Método no permitido en la ruta | 1 | 4 |
| `409` | Choque con el estado actual: sin stock, estado que no admite el cambio, datos repetidos | 10 | 8, 33, 38, 40, 47, 76, 85, 88, 92, 143 |
| `429` | Demasiados intentos de login con la misma cuenta | 1 | 26 |

Las 21 pruebas de acceso sin sesión, con credenciales incorrectas o sin permiso cubren los tres perfiles: la clienta pide rutas de gestión, el almacén intenta confirmar pagos, cancelar, exportar, cargar o aceptar albaranes y revisar informes, y un visitante sin sesión pide rutas privadas.

## Pruebas obligatorias de la defensa

| Prueba | Nº de prueba |
|---|---|
| Registro, login, acceso denegado y operación autorizada de un empleado | 7, 13, 9, 83 |
| Pedido con varias líneas: importe, descuento de stock y persistencia | 36, 37 |
| Cantidad no válida, pedido sin stock y pedido de otro cliente | 32, 33, 31 |
| Búsqueda filtrada, informe y exportación | 19, 109, 122 |
| Cancelación con reposición de stock y cancelación no permitida | 39, 38, 40 |
| Seguridad: CSRF, fuerza bruta y auditoría sin datos sensibles | 25, 26, 120 |

## Detalle de cada prueba

«Resultado esperado» son las comprobaciones que hace la colección; «resultado obtenido», el código que devolvió la aplicación y cuántas comprobaciones se cumplieron.

### 1. Sistema

4 pruebas · 9 comprobaciones · 0 fallos

| Nº | Prueba | Petición | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|
| 1 | Estado del servicio (200) | `GET /health` | 200 OK<br>App y base de datos disponibles | `200 OK` · 2/2 ✓ |
| 2 | Inicio de la API (200) | `GET /api` | 200 OK<br>Responde en JSON<br>Nombre de la API | `200 OK` · 3/3 ✓ |
| 3 | Ruta inexistente (404 en JSON) | `GET /api/no-existe` | 404 Not Found<br>Error en formato JSON | `404 Not Found` · 2/2 ✓ |
| 4 | Método no permitido (405) | `DELETE /api` | 405 Method Not Allowed<br>Cabecera Allow: GET | `405 Method Not Allowed` · 2/2 ✓ |

### 2. Registro y sesión

9 pruebas · 17 comprobaciones · 0 fallos

| Nº | Prueba | Petición | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|
| 5 | Sesión anónima (200) | `GET /api/sesion` | 200 OK<br>Sin sesión iniciada<br>Token CSRF de 64 caracteres | `200 OK` · 3/3 ✓ |
| 6 | Registro con datos no válidos (400) | `POST /api/registro` | 400 Bad Request<br>Errores de cada campo | `400 Bad Request` · 2/2 ✓ |
| 7 | Registro de cliente (201) | `POST /api/registro` | 201 Created<br>Cabecera Location<br>Cuenta con rol cliente<br>Nunca devuelve la contraseña | `201 Created` · 4/4 ✓ |
| 8 | Registro con email repetido (409) | `POST /api/registro` | 409 Conflict | `409 Conflict` · 1/1 ✓ |
| 9 | Cliente pide la lista de usuarios (403) | `GET /api/usuarios` | 403 Forbidden | `403 Forbidden` · 1/1 ✓ |
| 10 | Cerrar sesión (204) | `POST /api/logout` | 204 No Content | `204 No Content` · 1/1 ✓ |
| 11 | Sin sesión (401) | `GET /api/usuarios` | 401 Unauthorized | `401 Unauthorized` · 1/1 ✓ |
| 12 | Login con contraseña incorrecta (401) | `POST /api/login` | 401 Unauthorized<br>Mensaje genérico | `401 Unauthorized` · 2/2 ✓ |
| 13 | Login de administración (200) | `POST /api/login` | 200 OK<br>Rol admin | `200 OK` · 2/2 ✓ |

### 3. Usuarios (administración)

3 pruebas · 7 comprobaciones · 0 fallos

| Nº | Prueba | Petición | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|
| 14 | Listado paginado (200) | `GET /api/usuarios?pagina=1&por_pagina=5` | 200 OK<br>Como mucho 5 usuarios<br>Datos de paginación<br>Sin hashes de contraseña | `200 OK` · 4/4 ✓ |
| 15 | Filtro por rol (200) | `GET /api/usuarios?rol=almacen` | 200 OK<br>Solo personal de almacén | `200 OK` · 2/2 ✓ |
| 16 | Paginación no válida (400) | `GET /api/usuarios?por_pagina=500` | 400 Bad Request | `400 Bad Request` · 1/1 ✓ |

### 4. Catálogo

8 pruebas · 24 comprobaciones · 0 fallos

| Nº | Prueba | Petición | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|
| 17 | Listado paginado (200) | `GET /api/productos?pagina=1&por_pagina=4` | 200 OK<br>4 productos en la página<br>Al menos 8 productos en total<br>Precio, foto, color, tallas y stock | `200 OK` · 4/4 ✓ |
| 18 | Filtros combinados: categoría y orden (200) | `GET /api/productos?categoria=3&orden=precio_desc` | 200 OK<br>Solo la categoría 3 (Jordan)<br>Ordenados de mayor a menor precio | `200 OK` · 3/3 ✓ |
| 19 | Búsqueda por texto y talla (200) | `GET /api/productos?buscar=dunk&talla=40` | 200 OK<br>DD1391-103: es una Dunk con la talla 40<br>DD1391-100: es una Dunk con la talla 40 | `200 OK` · 3/3 ✓ |
| 20 | Ficha de producto (200) | `GET /api/productos/nike-air-force-1-07-cw2288-111` | 200 OK<br>Código de estilo de Nike<br>Otro color del mismo modelo (CW2288-001)<br>Tallas con stock y su variante_id (lo que se envía al pedir)<br>Personalizable: ofrece el bordado y las opciones de presentación<br>La caja de madera está desactivada y no se ofrece | `200 OK` · 6/6 ✓ |
| 21 | Las Jordan no ofrecen bordado (200) | `GET /api/productos/air-jordan-4-retro-dh6927-111` | 200 OK<br>No personalizable<br>Sin bordado, pero con caja | `200 OK` · 3/3 ✓ |
| 22 | Producto inexistente (404) | `GET /api/productos/no-existe` | 404 Not Found | `404 Not Found` · 1/1 ✓ |
| 23 | Valores para los filtros (200) | `GET /api/catalogo/filtros` | 200 OK<br>Todos los grupos de filtros | `200 OK` · 2/2 ✓ |
| 24 | Filtros no válidos (400) | `GET /api/productos?talla=42.3&precio_min=200&precio_max=100&orden=raro` | 400 Bad Request<br>Un error por cada filtro mal escrito | `400 Bad Request` · 2/2 ✓ |

### 5. Seguridad

3 pruebas · 5 comprobaciones · 0 fallos

| Nº | Prueba | Petición | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|
| 25 | Token CSRF falso (403) | `POST /api/logout` | 403 Forbidden<br>Motivo: CSRF | `403 Forbidden` · 2/2 ✓ |
| 26 | Fuerza bruta: sexto intento bloqueado (429) | `POST /api/login` | 429 Too Many Requests<br>Cabecera Retry-After | `429 Too Many Requests` · 2/2 ✓ |
| 27 | Cerrar la sesión de administración (204) | `POST /api/logout` | 204 No Content | `204 No Content` · 1/1 ✓ |

### 6. Pedidos del cliente

14 pruebas · 32 comprobaciones · 0 fallos

| Nº | Prueba | Petición | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|
| 28 | Sin sesión (401) | `GET /api/pedidos` | 401 Unauthorized | `401 Unauthorized` · 1/1 ✓ |
| 29 | Login de la clienta (200) | `POST /api/login` | 200 OK<br>Rol cliente | `200 OK` · 2/2 ✓ |
| 30 | Mis pedidos (200) | `GET /api/pedidos?por_pagina=50` | 200 OK<br>Todos son suyos<br>Datos de paginación | `200 OK` · 3/3 ✓ |
| 31 | Pedido de otro cliente (404, IDOR) | `GET /api/pedidos/SF-2026-000022` | 404 Not Found | `404 Not Found` · 1/1 ✓ |
| 32 | Cantidad no válida (400) | `POST /api/pedidos` | 400 Bad Request<br>Un error por cada línea mal escrita | `400 Bad Request` · 2/2 ✓ |
| 33 | Pedido sin stock suficiente (409) | `POST /api/pedidos` | 409 Conflict<br>Dice qué talla falta y cuánto queda | `409 Conflict` · 2/2 ✓ |
| 34 | Bordar unas Jordan (400) | `POST /api/pedidos` | 400 Bad Request<br>El error señala la opción de la línea | `400 Bad Request` · 2/2 ✓ |
| 35 | Opción desactivada: caja de madera (400) | `POST /api/pedidos` | 400 Bad Request<br>Opción no disponible | `400 Bad Request` · 2/2 ✓ |
| 36 | Crear pedido personalizado con dos líneas (201) | `POST /api/pedidos` | 201 Created<br>Location apunta al pedido nuevo<br>Pendiente y personalizado<br>Total calculado en el servidor: 2 pares con caja + 1 par bordado (el precio_unitario: 1 enviado se ignora)<br>Stock descontado (2 pares menos) | `201 Created` · 5/5 ✓ |
| 37 | Ver el pedido creado (200) | `GET /api/pedidos/SF-2026-000025` | 200 OK<br>Dos líneas con el precio del catálogo<br>La segunda línea conserva el nombre bordado y su recargo<br>Historial: creado como pendiente<br>Se puede cancelar | `200 OK` · 5/5 ✓ |
| 38 | Cancelar un pedido ya enviado (409) | `POST /api/pedidos/SF-2026-000013/cancelar` | 409 Conflict<br>Indica el estado actual | `409 Conflict` · 2/2 ✓ |
| 39 | Cancelar el pedido creado (200) | `POST /api/pedidos/SF-2026-000025/cancelar` | 200 OK<br>Estado cancelado<br>Stock repuesto | `200 OK` · 3/3 ✓ |
| 40 | Cancelarlo otra vez (409) | `POST /api/pedidos/SF-2026-000025/cancelar` | 409 Conflict | `409 Conflict` · 1/1 ✓ |
| 41 | Cerrar la sesión de la clienta (204) | `POST /api/logout` | 204 No Content | `204 No Content` · 1/1 ✓ |

### 7. Catálogo interno (admin)

22 pruebas · 49 comprobaciones · 0 fallos

| Nº | Prueba | Petición | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|
| 42 | Login de administración (200) | `POST /api/login` | 200 OK<br>Rol admin | `200 OK` · 2/2 ✓ |
| 43 | Listado interno (200) | `GET /api/gestion/productos?pagina=1&por_pagina=5` | 200 OK<br>Como mucho 5 productos<br>Datos de paginación<br>Datos internos: activo, stock total y número de tallas | `200 OK` · 4/4 ✓ |
| 44 | Alta con datos no válidos (400) | `POST /api/gestion/productos` | 400 Bad Request<br>Un error por cada campo y por cada talla mal escrita | `400 Bad Request` · 2/2 ✓ |
| 45 | Alta con categoría que no existe (400) | `POST /api/gestion/productos` | 400 Bad Request<br>Error en categoria_id | `400 Bad Request` · 2/2 ✓ |
| 46 | Alta de una zapatilla de prueba (201) | `POST /api/gestion/productos` | 201 Created<br>Cabecera Location<br>Código en mayúsculas<br>Slug a partir del nombre y el código<br>Imagen por convención<br>No personalizable por defecto<br>Tallas ordenadas y con stock 0<br>RFID con la fórmula de la semilla | `201 Created` · 8/8 ✓ |
| 47 | Alta con código repetido (409) | `POST /api/gestion/productos` | 409 Conflict<br>Dice qué código choca | `409 Conflict` · 2/2 ✓ |
| 48 | Ficha interna con RFID (200) | `GET /api/gestion/productos/22` | 200 OK<br>Tallas con stock mínimo, RFID y activo | `200 OK` · 2/2 ✓ |
| 49 | Producto que no existe (404) | `GET /api/gestion/productos/999999` | 404 Not Found | `404 Not Found` · 1/1 ✓ |
| 50 | Editar el precio (200) | `PATCH /api/gestion/productos/22` | 200 OK<br>Precio cambiado<br>Personalizable<br>El nombre no se ha tocado | `200 OK` · 4/4 ✓ |
| 51 | Cambiar el código de estilo (400) | `PATCH /api/gestion/productos/22` | 400 Bad Request<br>Error en codigo_marca | `400 Bad Request` · 2/2 ✓ |
| 52 | Edición sin campos (400) | `PATCH /api/gestion/productos/22` | 400 Bad Request | `400 Bad Request` · 1/1 ✓ |
| 53 | Desactivar (204) | `DELETE /api/gestion/productos/22` | 204 No Content | `204 No Content` · 1/1 ✓ |
| 54 | Desactivada: no sale en el catálogo público (404) | `GET /api/productos/nike-prueba-interna-zz7977-259` | 404 Not Found | `404 Not Found` · 1/1 ✓ |
| 55 | Desactivada: sigue en el listado interno (200) | `GET /api/gestion/productos?activo=false&buscar=ZZ7977-259` | 200 OK<br>Aparece desactivada | `200 OK` · 2/2 ✓ |
| 56 | Albarán con errores (400) | `POST /api/gestion/albaranes?referencia=ALB-PRUEBA-ERR` | 400 Bad Request<br>Errores por número de línea del archivo | `400 Bad Request` · 2/2 ✓ |
| 57 | Cargar albarán (200) | `POST /api/gestion/albaranes?referencia=ALB-PRUEBA-ZZ7977-259` | 200 OK<br>Resumen del albarán<br>Avisa de la zapatilla desactivada (no la reactiva)<br>El stock de la talla 42 ha subido 3 pares | `200 OK` · 4/4 ✓ |
| 58 | Reactivar (200) | `PATCH /api/gestion/productos/22` | 200 OK<br>Vuelve a estar activa<br>Tiene la talla 46 con los 2 pares del albarán | `200 OK` · 3/3 ✓ |
| 59 | Cerrar la sesión de administración (204) | `POST /api/logout` | 204 No Content | `204 No Content` · 1/1 ✓ |
| 60 | Sin sesión (401) | `GET /api/gestion/productos` | 401 Unauthorized | `401 Unauthorized` · 1/1 ✓ |
| 61 | Login de la clienta (200) | `POST /api/login` | 200 OK<br>Rol cliente | `200 OK` · 2/2 ✓ |
| 62 | La clienta no puede gestionar el catálogo (403) | `POST /api/gestion/productos` | 403 Forbidden | `403 Forbidden` · 1/1 ✓ |
| 63 | Cerrar la sesión de la clienta (204) | `POST /api/logout` | 204 No Content | `204 No Content` · 1/1 ✓ |

### 8. Gestión de pedidos y stock

34 pruebas · 63 comprobaciones · 0 fallos

| Nº | Prueba | Petición | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|
| 64 | Login de la clienta (200) | `POST /api/login` | 200 OK<br>Rol cliente | `200 OK` · 2/2 ✓ |
| 65 | La clienta crea su pedido (201) | `POST /api/pedidos` | 201 Created<br>Pendiente y sin personalizar | `201 Created` · 2/2 ✓ |
| 66 | Cerrar la sesión de la clienta (204) | `POST /api/logout` | 204 No Content | `204 No Content` · 1/1 ✓ |
| 67 | Sin sesión (401) | `GET /api/gestion/pedidos` | 401 Unauthorized | `401 Unauthorized` · 1/1 ✓ |
| 68 | Login de almacén (200) | `POST /api/login` | 200 OK<br>Rol almacen | `200 OK` · 2/2 ✓ |
| 69 | Buscar el pedido por código (200) | `GET /api/gestion/pedidos?buscar=SF-2026-000026&personalizado=0` | 200 OK<br>Encuentra solo el pedido creado<br>Incluye el cliente | `200 OK` · 3/3 ✓ |
| 70 | Solo pedidos personalizados (200) | `GET /api/gestion/pedidos?personalizado=1&por_pagina=50` | 200 OK<br>Hay pedidos y todos son personalizados | `200 OK` · 2/2 ✓ |
| 71 | Filtros no válidos (400) | `GET /api/gestion/pedidos?estado=perdido&personalizado=quizas` | 400 Bad Request<br>Un error por filtro | `400 Bad Request` · 2/2 ✓ |
| 72 | Detalle de gestión (200) | `GET /api/gestion/pedidos/SF-2026-000026` | 200 OK<br>El historial incluye el usuario | `200 OK` · 2/2 ✓ |
| 73 | Pedido inexistente (404) | `GET /api/gestion/pedidos/SF-2026-999999` | 404 Not Found | `404 Not Found` · 1/1 ✓ |
| 74 | El almacén no puede confirmar el pago (403) | `PATCH /api/gestion/pedidos/SF-2026-000026/estado` | 403 Forbidden | `403 Forbidden` · 1/1 ✓ |
| 75 | El almacén no puede cancelar (403) | `PATCH /api/gestion/pedidos/SF-2026-000026/estado` | 403 Forbidden | `403 Forbidden` · 1/1 ✓ |
| 76 | Preparar sin confirmar el pago (409) | `PATCH /api/gestion/pedidos/SF-2026-000026/estado` | 409 Conflict<br>Indica el estado actual y los permitidos | `409 Conflict` · 2/2 ✓ |
| 77 | Cerrar la sesión de almacén (204) | `POST /api/logout` | 204 No Content | `204 No Content` · 1/1 ✓ |
| 78 | Login de administración (200) | `POST /api/login` | 200 OK<br>Rol admin | `200 OK` · 2/2 ✓ |
| 79 | Volver a pendiente (400) | `PATCH /api/gestion/pedidos/SF-2026-000026/estado` | 400 Bad Request<br>Error en estado | `400 Bad Request` · 2/2 ✓ |
| 80 | Administración confirma el pago (200) | `PATCH /api/gestion/pedidos/SF-2026-000026/estado` | 200 OK<br>Confirmado<br>Historial: comentario por defecto y rol admin | `200 OK` · 3/3 ✓ |
| 81 | Cerrar la sesión de administración (204) | `POST /api/logout` | 204 No Content | `204 No Content` · 1/1 ✓ |
| 82 | Login de almacén (200) | `POST /api/login` | 200 OK<br>Rol almacen | `200 OK` · 2/2 ✓ |
| 83 | El almacén lo pasa a preparación (200) | `PATCH /api/gestion/pedidos/SF-2026-000026/estado` | 200 OK<br>En preparación con el comentario enviado | `200 OK` · 2/2 ✓ |
| 84 | Enviar sin código de seguimiento (400) | `PATCH /api/gestion/pedidos/SF-2026-000026/estado` | 400 Bad Request<br>Error en codigo_seguimiento | `400 Bad Request` · 2/2 ✓ |
| 85 | Código de seguimiento de otro pedido (409) | `PATCH /api/gestion/pedidos/SF-2026-000026/estado` | 409 Conflict | `409 Conflict` · 1/1 ✓ |
| 86 | El almacén lo entrega al transportista (200) | `PATCH /api/gestion/pedidos/SF-2026-000026/estado` | 200 OK<br>Enviado con el seguimiento en mayúsculas<br>Ya no se puede cancelar | `200 OK` · 3/3 ✓ |
| 87 | El almacén lo marca como entregado (200) | `PATCH /api/gestion/pedidos/SF-2026-000026/estado` | 200 OK<br>Entregado<br>Historial completo: 5 estados<br>Comentario por defecto | `200 OK` · 4/4 ✓ |
| 88 | Un pedido entregado ya no cambia (409) | `PATCH /api/gestion/pedidos/SF-2026-000026/estado` | 409 Conflict | `409 Conflict` · 1/1 ✓ |
| 89 | Tallas bajo el stock mínimo (200) | `GET /api/gestion/stock?bajo_minimo=1&por_pagina=100` | 200 OK<br>Todas están en o por debajo del mínimo | `200 OK` · 2/2 ✓ |
| 90 | Stock del modelo del pedido (200) | `GET /api/gestion/stock?producto_id=3&por_pagina=100` | 200 OK<br>Aparece la talla del pedido con su RFID | `200 OK` · 2/2 ✓ |
| 91 | Ajuste con cantidad 0 y sin motivo (400) | `PATCH /api/gestion/variantes/23/stock` | 400 Bad Request<br>Error en los dos campos | `400 Bad Request` · 2/2 ✓ |
| 92 | Ajuste que dejaría el stock en negativo (409) | `PATCH /api/gestion/variantes/23/stock` | 409 Conflict<br>Indica el stock disponible | `409 Conflict` · 2/2 ✓ |
| 93 | Ajustar: un par con la caja dañada (200) | `PATCH /api/gestion/variantes/23/stock` | 200 OK<br>Un par menos | `200 OK` · 2/2 ✓ |
| 94 | Deshacer el ajuste (200) | `PATCH /api/gestion/variantes/23/stock` | 200 OK<br>Stock como estaba | `200 OK` · 2/2 ✓ |
| 95 | Movimientos de la talla (200) | `GET /api/gestion/variantes/23/movimientos?por_pagina=3` | 200 OK<br>Los dos ajustes son los más recientes<br>Después, la venta del pedido creado | `200 OK` · 3/3 ✓ |
| 96 | Talla inexistente (404) | `PATCH /api/gestion/variantes/999999/stock` | 404 Not Found | `404 Not Found` · 1/1 ✓ |
| 97 | Cerrar la sesión de almacén (204) | `POST /api/logout` | 204 No Content | `204 No Content` · 1/1 ✓ |

### 9. Perfil, consultas, informes y exportación

32 pruebas · 70 comprobaciones · 0 fallos

| Nº | Prueba | Petición | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|
| 98 | Login de la clienta (200) | `POST /api/login` | 200 OK<br>Rol cliente | `200 OK` · 2/2 ✓ |
| 99 | Mi perfil (200) | `GET /api/perfil` | 200 OK<br>Datos de la cuenta y del perfil de cliente<br>Sin DNI | `200 OK` · 3/3 ✓ |
| 100 | Editar mi perfil (200) | `PATCH /api/perfil` | 200 OK<br>Cambian el teléfono y la ciudad<br>El email no se puede cambiar desde el perfil<br>Los campos no enviados no cambian | `200 OK` · 4/4 ✓ |
| 101 | Editar el perfil con datos no válidos (400) | `PATCH /api/perfil` | 400 Bad Request<br>Un error por cada campo no válido | `400 Bad Request` · 2/2 ✓ |
| 102 | Editar el perfil sin campos editables (400) | `PATCH /api/perfil` | 400 Bad Request | `400 Bad Request` · 1/1 ✓ |
| 103 | Dejar el perfil como estaba (200) | `PATCH /api/perfil` | 200 OK<br>Perfil restaurado | `200 OK` · 2/2 ✓ |
| 104 | Cerrar la sesión de la clienta (204) | `POST /api/logout` | 204 No Content | `204 No Content` · 1/1 ✓ |
| 105 | Perfil sin sesión (401) | `GET /api/perfil` | 401 Unauthorized | `401 Unauthorized` · 1/1 ✓ |
| 106 | Enviar una consulta de contacto (201) | `POST /api/consultas` | 201 Created<br>Guardada como pendiente<br>Email en minúsculas | `201 Created` · 3/3 ✓ |
| 107 | Login de administración (200) | `POST /api/login` | 200 OK<br>Rol admin | `200 OK` · 2/2 ✓ |
| 108 | Panel resumen (200) | `GET /api/gestion/resumen` | 200 OK<br>Los 6 estados de pedido<br>Indicadores numéricos<br>5 modelos ordenados de menos a más stock | `200 OK` · 4/4 ✓ |
| 109 | Informe: ventas por modelo (200) | `GET /api/gestion/informes/ventas-por-modelo` | 200 OK<br>Cada fila con el código de estilo de Nike<br>Ordenado por ingresos<br>Las unidades suman lo mismo que el resumen | `200 OK` · 4/4 ✓ |
| 110 | Informe: ventas por mes (200) | `GET /api/gestion/informes/ventas-por-mes` | 200 OK<br>Meses con formato AAAA-MM<br>Los ingresos suman lo mismo que el resumen | `200 OK` · 3/3 ✓ |
| 111 | Informe: ventas de octubre (200) | `GET /api/gestion/informes/ventas-por-mes?desde=2026-10-01&hasta=2026-10-31` | 200 OK<br>Devuelve el rango aplicado<br>Solo octubre | `200 OK` · 3/3 ✓ |
| 112 | Informe: personalizaciones (200) | `GET /api/gestion/informes/personalizaciones` | 200 OK<br>Opciones del catálogo de personalización<br>Se vende el nombre bordado | `200 OK` · 3/3 ✓ |
| 113 | Informe inexistente (404) | `GET /api/gestion/informes/clientes` | 404 Not Found | `404 Not Found` · 1/1 ✓ |
| 114 | Informe con fechas no válidas (400) | `GET /api/gestion/informes/ventas-por-mes?desde=2026-11-30&hasta=2026-02-30` | 400 Bad Request<br>Error en la fecha que no existe | `400 Bad Request` · 2/2 ✓ |
| 115 | Consultas pendientes (200) | `GET /api/gestion/consultas?estado=pendiente` | 200 OK<br>Solo pendientes, con paginación<br>Incluye la consulta enviada | `200 OK` · 3/3 ✓ |
| 116 | Marcar la consulta como respondida (200) | `PATCH /api/gestion/consultas/4` | 200 OK<br>Estado respondida | `200 OK` · 2/2 ✓ |
| 117 | Estado de consulta no válido (400) | `PATCH /api/gestion/consultas/4` | 400 Bad Request | `400 Bad Request` · 1/1 ✓ |
| 118 | Consulta inexistente (404) | `PATCH /api/gestion/consultas/999999` | 404 Not Found | `404 Not Found` · 1/1 ✓ |
| 119 | Auditoría: cambios de estado de consultas (200) | `GET /api/gestion/auditoria?tipo=consulta.cambiar_estado&por_pagina=5` | 200 OK<br>El último evento es el cambio de la consulta enviada | `200 OK` · 2/2 ✓ |
| 120 | Auditoría: la edición del perfil no guarda los datos (200) | `GET /api/gestion/auditoria?tipo=perfil.editar&por_pagina=1` | 200 OK<br>Solo la lista de campos | `200 OK` · 2/2 ✓ |
| 121 | Filtros de auditoría no válidos (400) | `GET /api/gestion/auditoria?resultado=ok&usuario_id=-1` | 400 Bad Request<br>Un error por filtro | `400 Bad Request` · 2/2 ✓ |
| 122 | Exportar pedidos en CSV (200) | `GET /api/gestion/exportar/pedidos?formato=csv` | 200 OK<br>Descarga kalma-pedidos-AAAAMMDD.csv<br>Empieza por el BOM de UTF-8<br>Cabecera con punto y coma | `200 OK` · 4/4 ✓ |
| 123 | Exportar pedidos entregados en JSON (200) | `GET /api/gestion/exportar/pedidos?formato=json&estado=entregado` | 200 OK<br>Descarga kalma-pedidos-AAAAMMDD.json<br>Solo entregados, cada uno con sus líneas<br>El total de cada pedido es la suma de sus líneas | `200 OK` · 4/4 ✓ |
| 124 | Formato de exportación no válido (400) | `GET /api/gestion/exportar/pedidos?formato=xlsx` | 400 Bad Request<br>Error en el formato | `400 Bad Request` · 2/2 ✓ |
| 125 | Cerrar la sesión de administración (204) | `POST /api/logout` | 204 No Content | `204 No Content` · 1/1 ✓ |
| 126 | Login del almacén (200) | `POST /api/login` | 200 OK<br>Rol almacen | `200 OK` · 2/2 ✓ |
| 127 | El almacén pide el panel resumen (403) | `GET /api/gestion/resumen` | 403 Forbidden | `403 Forbidden` · 1/1 ✓ |
| 128 | El almacén intenta exportar (403) | `GET /api/gestion/exportar/pedidos?formato=csv` | 403 Forbidden | `403 Forbidden` · 1/1 ✓ |
| 129 | Cerrar la sesión del almacén (204) | `POST /api/logout` | 204 No Content | `204 No Content` · 1/1 ✓ |

### Albaranes (lista y detalle)

11 pruebas · 22 comprobaciones · 0 fallos

| Nº | Prueba | Petición | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|
| 130 | Login de almacén (200) | `POST /api/login` | 200 OK<br>Rol almacen | `200 OK` · 2/2 ✓ |
| 131 | El almacén ve la lista de albaranes (200) | `GET /api/gestion/albaranes?por_pagina=5` | 200 OK<br>Lista paginada con al menos un albarán<br>Cada albarán trae su resumen, quién y cuándo<br>Del más reciente al más antiguo<br>Incluye el albarán que cargó la carpeta 7 | `200 OK` · 5/5 ✓ |
| 132 | Detalle de un albarán que existe (200) | `GET /api/gestion/albaranes/detalle?referencia=ALB-PRUEBA-ZZ7977-259` | 200 OK<br>Es el albarán pedido<br>Resumen igual que al cargarlo<br>Las tallas que entraron suman las unidades del albarán<br>La Air Force 1 en la 42 entró con 3 pares | `200 OK` · 5/5 ✓ |
| 133 | Detalle de un albarán que no existe (404) | `GET /api/gestion/albaranes/detalle?referencia=ALB/NO-EXISTE/0000` | 404 Not Found<br>Error en el formato de la API | `404 Not Found` · 2/2 ✓ |
| 134 | Detalle sin referencia (400) | `GET /api/gestion/albaranes/detalle` | 400 Bad Request<br>Dice qué falta | `400 Bad Request` · 2/2 ✓ |
| 135 | El almacén no puede cargar un albarán (403) | `POST /api/gestion/albaranes?referencia=ALB-ALMACEN` | 403 Forbidden | `403 Forbidden` · 1/1 ✓ |
| 136 | Cerrar la sesión de almacén (204) | `POST /api/logout` | 204 No Content | `204 No Content` · 1/1 ✓ |
| 137 | Login de la clienta (200) | `POST /api/login` | 200 OK | `200 OK` · 1/1 ✓ |
| 138 | La clienta pide la lista de albaranes (403) | `GET /api/gestion/albaranes` | 403 Forbidden | `403 Forbidden` · 1/1 ✓ |
| 139 | Cerrar la sesión de la clienta (204) | `POST /api/logout` | 204 No Content | `204 No Content` · 1/1 ✓ |
| 140 | Sin sesión (401) | `GET /api/gestion/albaranes` | 401 Unauthorized | `401 Unauthorized` · 1/1 ✓ |

### Albaranes repetidos

12 pruebas · 23 comprobaciones · 0 fallos

| Nº | Prueba | Petición | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|
| 141 | Login de administración (200) | `POST /api/login` | 200 OK<br>Rol admin | `200 OK` · 2/2 ✓ |
| 142 | Primera carga de una referencia nueva (200) | `POST /api/gestion/albaranes?referencia=ALB-REP-MWO0OH6W-621` | 200 OK<br>No es repetida | `200 OK` · 2/2 ✓ |
| 143 | Recargar la misma referencia sin aceptarla (409) | `POST /api/gestion/albaranes?referencia=ALB-REP-MWO0OH6W-621` | 409 Conflict<br>Trae la carga anterior<br>El stock no ha cambiado | `409 Conflict` · 3/3 ✓ |
| 144 | Aceptar la repetida sin motivo (400) | `POST /api/gestion/albaranes?referencia=ALB-REP-MWO0OH6W-621&aceptar_repetida=1` | 400 Bad Request<br>El error está en el motivo<br>El stock sigue sin cambiar | `400 Bad Request` · 3/3 ✓ |
| 145 | Aceptar la repetida con motivo (200) | `POST /api/gestion/albaranes?referencia=ALB-REP-MWO0OH6W-621&aceptar_repetida=1&motivo=Segunda entrega del proveedor` | 200 OK<br>Marcada como repetida con su motivo<br>Ahora sí ha sumado un par | `200 OK` · 3/3 ✓ |
| 146 | El intento rechazado sale en los avisos (200) | `GET /api/gestion/albaranes/avisos?por_pagina=10` | 200 OK<br>Un solo aviso de esta referencia (el 400 no cuenta), ya cargada después | `200 OK` · 2/2 ✓ |
| 147 | El detalle es la carga repetida (200) | `GET /api/gestion/albaranes/detalle?referencia=ALB-REP-MWO0OH6W-621` | 200 OK<br>Dos cargas y la última es la repetida | `200 OK` · 2/2 ✓ |
| 148 | Cerrar la sesión de administración (204) | `POST /api/logout` | 204 No Content | `204 No Content` · 1/1 ✓ |
| 149 | Login de almacén (200) | `POST /api/login` | 200 OK<br>Rol almacen | `200 OK` · 2/2 ✓ |
| 150 | El almacén no puede aceptar una repetida (403) | `POST /api/gestion/albaranes?referencia=ALB-REP-MWO0OH6W-621&aceptar_repetida=1&motivo=Intento del almacén` | 403 Forbidden | `403 Forbidden` · 1/1 ✓ |
| 151 | El almacén no ve los avisos (403) | `GET /api/gestion/albaranes/avisos` | 403 Forbidden | `403 Forbidden` · 1/1 ✓ |
| 152 | Cerrar la sesión de almacén (204) | `POST /api/logout` | 204 No Content | `204 No Content` · 1/1 ✓ |

### Informes de stock

24 pruebas · 54 comprobaciones · 0 fallos

| Nº | Prueba | Petición | Resultado esperado | Resultado obtenido |
|---|---|---|---|---|
| 153 | Login del almacén (200) | `POST /api/login` | 200 OK<br>Rol almacen | `200 OK` · 2/2 ✓ |
| 154 | Vista previa de poco stock (200) | `GET /api/gestion/informes-stock/vista-previa?tipo=stock_bajo` | 200 OK<br>Tipo y umbral<br>Todas las tallas en su mínimo o por debajo<br>total_filas cuadra con las filas | `200 OK` · 4/4 ✓ |
| 155 | Vista previa sin ventas en 60 días (200) | `GET /api/gestion/informes-stock/vista-previa?tipo=sin_ventas&dias=60` | 200 OK<br>Parámetros: 60 días<br>Ninguna se ha vendido en los últimos 60 días | `200 OK` · 3/3 ✓ |
| 156 | Días fuera de rango (400) | `GET /api/gestion/informes-stock/vista-previa?tipo=sin_ventas&dias=366` | 400 Bad Request<br>El error es de dias | `400 Bad Request` · 2/2 ✓ |
| 157 | Envío sin días (400) | `POST /api/gestion/informes-stock` | 400 Bad Request<br>Falta dias | `400 Bad Request` · 2/2 ✓ |
| 158 | Tipo no válido (400) | `GET /api/gestion/informes-stock/vista-previa?tipo=ofertas` | 400 Bad Request<br>El error es de tipo | `400 Bad Request` · 2/2 ✓ |
| 159 | Vista previa en CSV (200) | `GET /api/gestion/informes-stock/vista-previa/csv?tipo=sin_ventas&dias=60` | 200 OK<br>Descarga text/csv con nombre del servidor<br>Cabecera con punto y coma (y BOM de UTF-8 delante) | `200 OK` · 3/3 ✓ |
| 160 | Enviar el informe a administración (201) | `POST /api/gestion/informes-stock` | 201 Created<br>Location apunta al informe<br>Pendiente, con su foto y su comentario | `201 Created` · 3/3 ✓ |
| 161 | Mis informes enviados (200) | `GET /api/gestion/informes-stock?por_pagina=50` | 200 OK<br>Está el que acabo de enviar, pendiente<br>Solo los suyos y sin la foto en el listado<br>El contador de pendientes es solo para quien revisa | `200 OK` · 4/4 ✓ |
| 162 | CSV del informe enviado (200) | `GET /api/gestion/informes-stock/186/csv` | 200 OK<br>Descarga text/csv con nombre del servidor<br>Cabecera con punto y coma (y BOM de UTF-8 delante) | `200 OK` · 3/3 ✓ |
| 163 | El almacén no puede marcarlo como revisado (403) | `PATCH /api/gestion/informes-stock/186/revisado` | 403 Forbidden<br>Formato de error | `403 Forbidden` · 2/2 ✓ |
| 164 | Cerrar la sesión del almacén (204) | `POST /api/logout` | 204 No Content | `204 No Content` · 1/1 ✓ |
| 165 | Login de administración (200) | `POST /api/login` | 200 OK<br>Rol admin | `200 OK` · 2/2 ✓ |
| 166 | Bandeja de pendientes (200) | `GET /api/gestion/informes-stock?estado=pendiente&por_pagina=50` | 200 OK<br>Está el informe del almacén<br>Todos pendientes y el contador cuadra | `200 OK` · 3/3 ✓ |
| 167 | Detalle del informe (200) | `GET /api/gestion/informes-stock/186` | 200 OK<br>Tipo, foto y quién lo envió | `200 OK` · 2/2 ✓ |
| 168 | Marcar como revisado (200) | `PATCH /api/gestion/informes-stock/186/revisado` | 200 OK<br>Revisado por administración | `200 OK` · 2/2 ✓ |
| 169 | Marcarlo otra vez no cambia nada (200) | `PATCH /api/gestion/informes-stock/186/revisado` | 200 OK<br>Misma fecha de revisión | `200 OK` · 2/2 ✓ |
| 170 | Envío y revisión en la auditoría (200) | `GET /api/gestion/auditoria?entidad=informe_stock&por_pagina=100` | 200 OK<br>El envío guarda la foto<br>Una sola revisión de ese informe | `200 OK` · 3/3 ✓ |
| 171 | Informe que no existe (404) | `GET /api/gestion/informes-stock/999999999` | 404 Not Found | `404 Not Found` · 1/1 ✓ |
| 172 | Cerrar la sesión de administración (204) | `POST /api/logout` | 204 No Content | `204 No Content` · 1/1 ✓ |
| 173 | Login de la clienta (200) | `POST /api/login` | 200 OK<br>Rol cliente | `200 OK` · 2/2 ✓ |
| 174 | La clienta no ve la vista previa (403) | `GET /api/gestion/informes-stock/vista-previa?tipo=stock_bajo` | 403 Forbidden<br>Formato de error | `403 Forbidden` · 2/2 ✓ |
| 175 | La clienta no ve los informes (403) | `GET /api/gestion/informes-stock` | 403 Forbidden<br>Formato de error | `403 Forbidden` · 2/2 ✓ |
| 176 | Cerrar la sesión de la clienta (204) | `POST /api/logout` | 204 No Content | `204 No Content` · 1/1 ✓ |
