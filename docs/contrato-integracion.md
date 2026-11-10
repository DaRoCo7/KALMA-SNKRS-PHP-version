# KALMA SNKRS · Contrato de integración

Acuerdo técnico entre los módulos de **Desarrollo Web en Entorno Servidor** (la aplicación PHP),
**Despliegue de Aplicaciones Web** (la infraestructura) y el **front** (páginas + JavaScript).
Cada módulo puede cambiar su parte por dentro, pero lo que aparece aquí no se cambia sin
avisar al resto y actualizar este documento en el mismo commit.

---

## 1. Arquitectura

```
Navegador ──HTTPS 443──► proxy (Nginx) ──red front──► app (PHP 8.3 + Apache) ──red back──► db (PostgreSQL 17)
                          │ estáticos /css /js /img                                        │
                          └ logs de acceso y error                                         └ volumen datos_postgres
```

| Servicio | Imagen | Puerto interno | Publicado en el host | Redes |
|---|---|---|---|---|
| `proxy` | Propia, basada en `nginx:1.27-alpine`, con `public/` copiado dentro | 80, 443 | **80 y 443** | `front` |
| `app` | Propia (`Dockerfile`, 2 etapas, `php:8.3-apache`) | 80 | No | `front`, `back` |
| `db` | `postgres:17` | 5432 | **Nunca** | `back` |

**Quién habla con quién**

| Origen → destino | ¿Permitido? | Motivo |
|---|---|---|
| Navegador → proxy | Sí | Única puerta de entrada |
| proxy → app | Sí (red `front`) | Proxy inverso |
| app → db | Sí (red `back`) | Consultas de la aplicación |
| proxy → db | **No** | El proxy no está en la red `back` |
| Navegador → app o db | **No** | No publican puertos |
| db → Internet | **No** | La red `back` es `internal: true` |

## 2. Variables de entorno

Se definen en `.env` (fuera de Git y de la imagen). La plantilla es `.env.example`.

| Variable | Obligatoria | Valor por defecto | La usa | Para qué |
|---|---|---|---|---|
| `DB_NAME` | Sí | — | app, db | Nombre de la base de datos |
| `DB_USER` | Sí | — | app, db | Usuario de PostgreSQL |
| `DB_PASSWORD` | Sí | — | app, db | Contraseña de PostgreSQL |
| `APP_ENTORNO` | No | `produccion` | app | `desarrollo` añade el motivo de los errores 500 a la respuesta |
| `LIMITE_LOGIN_IP` | No | `20` | app | Intentos de login por IP cada 15 min |
| `LIMITE_REGISTRO_IP` | No | `5` | app | Registros por IP cada hora |
| `APP_VERSION` | No | `dev` | compose | Etiqueta de las imágenes propias (permite volver a la anterior) |
| `DOMINIO` | No | `kalma.local` | proxy | Nombre del servidor y del certificado |

`DB_HOST=db` y `DB_PORT=5432` los fija `compose.yaml`: no se tocan desde `.env`.

## 3. Reparto de rutas HTTP

| Ruta | La atiende | Respuesta |
|---|---|---|
| `/css/*`, `/js/*`, `/img/*`, `/fonts/*` | Nginx directamente | Archivo estático con caché |
| `/health` | app | JSON de estado (ver punto 4) |
| `/api` y `/api/*` | app | Siempre JSON |
| Cualquier otra | app | Página HTML (o página 404) |

**Cabeceras que el proxy debe enviar siempre a la app**

| Cabecera | Para qué la usa la app |
|---|---|
| `X-Real-IP` | IP real del cliente: rate limiting y auditoría. Solo se acepta si la petición llega desde una red privada (el proxy) |
| `X-Forwarded-Proto` | Si vale `https`, la cookie de sesión se marca `Secure` |
| `Host` | Nombre con el que se pidió la web |

Si Nginx corta una petición por exceso (rate limiting), responde `429` con el mismo formato de error de la API.

## 4. Salud del servicio

`GET /health` (público, sin sesión). Lo usan el healthcheck de `compose.yaml` y el script de despliegue.

| Situación | Código | Cuerpo |
|---|---|---|
| App y base de datos funcionando | `200` | `{"app":"disponible","base_datos":"disponible"}` |
| La app funciona pero la BD no responde | `503` | `{"app":"disponible","base_datos":"no_disponible"}` |
| La app no responde | Sin respuesta o `502` del proxy | — |

## 5. Base de datos

- **Motor**: PostgreSQL 17. **Esquema**: `database/schema.sql`. **Diagrama E/R**: `docs/diagrama-er.md`.
- **Carga inicial**: con el volumen vacío, PostgreSQL ejecuta en orden los archivos montados en
  `/docker-entrypoint-initdb.d/`:

  | Orden | Archivo del repositorio | Contenido |
  |---|---|---|
  | `01_schema.sql` | `database/schema.sql` | Tablas, restricciones, índices y triggers |
  | `02_usuarios.sql` | `database/seeds/usuarios.sql` | Roles, usuarios, clientes y consultas |
  | `03_catalogo.sql` | `database/seeds/catalogo.sql` | Marca, categorías, colores, productos y tallas con su stock inicial |
  | `04_pedidos.sql` | `database/seeds/pedidos.sql` | Pedidos, líneas, historial y movimientos de stock |

- **Los scripts de arranque solo se ejecutan con el volumen vacío.** Con datos, se ignoran.
- **Cambios de esquema después del primer despliegue**: van en `database/migraciones/NNN_descripcion.sql`
  (numeradas, idempotentes y dentro de una transacción). El script de despliegue las aplica sobre la base de
  datos existente. Nunca se borra el volumen para aplicar un cambio.
- **Persistencia**: volumen `datos_postgres` → `/var/lib/postgresql/data`. Sobrevive a
  `docker compose down`, a `up --build` y a recrear contenedores. Solo `docker compose down -v` lo borra.

## 6. Logs y auditoría

| Qué | Dónde | Cómo se consulta |
|---|---|---|
| Accesos y errores del proxy | stdout y stderr de `proxy` | `docker compose logs proxy` |
| Errores y eventos de la aplicación | stderr de `app`, una línea JSON por evento (`fecha` ISO 8601, `nivel`, `mensaje`, `referencia`) | `docker compose logs app` |
| Eventos de negocio (login, pedidos, estados, stock, accesos denegados) | Tabla `auditoria` | `GET /api/gestion/auditoria` o `psql` |
| Movimientos de stock | Tabla `movimientos_stock` | `GET /api/gestion/stock` o `psql` |

- Un error 500 llega al cliente con una `referencia` (8 caracteres hexadecimales). La misma referencia aparece en
  el log de `app` con el detalle técnico.
- Ningún log ni la auditoría guardan contraseñas, hashes, tokens ni la cookie de sesión.

## 7. Perfiles de prueba

Todos los datos son ficticios. Contraseña de demostración de todas las cuentas: `Kalma2026!`

| Email | Rol | Para la demo |
|---|---|---|
| `admin@kalma.test` | admin | Todo: productos, informes, auditoría, exportación |
| `almacen@kalma.test` | almacen | Stock, ver todos los pedidos y avanzar su estado |
| `cliente@kalma.test` | cliente | Sus pedidos (`SF-2026-000021` cancelable, `SF-2026-000013` no) |

El pedido `SF-2026-000022` es de otro cliente: sirve para demostrar que `cliente@kalma.test` no puede verlo (404).

## 8. Procedimientos (responsabilidad de Despliegue)

| Procedimiento | Entregable |
|---|---|
| Despliegue desde cero | `cp .env.example .env`, rellenar, `./scripts/deploy.sh` (comprueba requisitos, construye con `APP_VERSION`, aplica migraciones, levanta y espera a que `/health` dé `200`) |
| Actualización | Nueva `APP_VERSION`, `deploy.sh`. Los datos se conservan |
| Reversión | Volver a la `APP_VERSION` anterior y `docker compose up -d` (la imagen anterior sigue en el host) |
| Copia de seguridad | `scripts/backup.sh`: `pg_dump -Fc` + configuración en `backups/AAAAMMDD-HHMM/`, registro del resultado, 5 copias, cron diario |
| Restauración | `scripts/restaurar.sh`: restaura en una base de datos aparte. Sobre producción solo con confirmación explícita |
| HTTPS | `https://kalma.local` con certificado de una CA local (mkcert). HTTP redirige a HTTPS |

---

## 9. Contrato de la API (para el front)

### 9.1 Convenciones

- Todas las rutas `/api/*` responden en JSON (`Content-Type: application/json; charset=utf-8`).
- **Éxito**: `{"datos": ...}`. Los listados añaden la paginación:
  ```json
  { "datos": [ ... ], "paginacion": { "pagina": 1, "por_pagina": 12, "total": 30, "paginas": 3 } }
  ```
  Parámetros de paginación: `?pagina=1&por_pagina=10` (máximo 50 por página).
- **Error**: `{"error": {"codigo": 409, "mensaje": "...", "detalles": { ... }}}`. `detalles` es opcional:

  | Código | Cuándo | `detalles` |
  |---|---|---|
  | 400 | Datos no válidos | `{"errores": {"campo": "motivo"}}` (todos los campos a la vez) |
  | 401 | Sin sesión | — |
  | 403 | Sin permiso o token CSRF no válido | — |
  | 404 | La ruta o el recurso no existen (o no son tuyos) | — |
  | 405 | Método no permitido en esa ruta | `{"permitidos": "GET, POST"}` |
  | 409 | Choca con el estado actual (sin stock, estado que no permite el cambio, email repetido) | Según el caso |
  | 413 / 415 | Cuerpo demasiado grande / no es `application/json` | — |
  | 429 | Demasiadas peticiones (cabecera `Retry-After`) | `{"reintentar_en_segundos": 900}` |
  | 500 | Error interno | `{"referencia": "a1b2c3d4"}` |

- Los cuerpos de las peticiones se envían como `application/json` (máximo 1 MB).
- Fechas en ISO 8601 con zona horaria (`2026-11-08T19:15:00+01:00`). Importes como número con decimales.
- **Precios y totales los calcula siempre el servidor.** El front no envía precios (si los envía, se ignoran).

### 9.2 Sesión y CSRF

1. La sesión va en la cookie `KALMA_SESION` (HttpOnly, SameSite=Lax, caduca tras 30 min sin actividad).
   Como front y API comparten origen, `fetch` la envía sola.
2. Toda petición `POST`, `PUT`, `PATCH` o `DELETE` lleva la cabecera `X-CSRF-Token`.
   - Las páginas incluyen el token en `<meta name="csrf-token" content="...">`.
   - También se obtiene con `GET /api/sesion`.
3. **El token cambia al iniciar sesión o registrarse.** El nuevo viene en la respuesta del login o del registro
   y el front debe usarlo a partir de ese momento.
4. `401` → llevar al usuario a `/login`. `403` → mostrar "no tienes permiso".

### 9.3 Endpoints

Permisos: ver la matriz en `app/Seguridad/Permisos.php` (admin tiene todos).

**Sistema y sesión**

| Método | Ruta | Permiso | Respuestas |
|---|---|---|---|
| GET | `/health` | Público | 200, 503 |
| GET | `/api` | Público | 200 |
| GET | `/api/sesion` | Público | 200 `{autenticado, usuario, csrf_token}` |
| POST | `/api/registro` | Público | 201 `{usuario, csrf_token}`, 400, 409, 429 |
| POST | `/api/login` | Público | 200 `{usuario, csrf_token}`, 400, 401, 403, 429 |
| POST | `/api/logout` | Sesión | 204 |

**Catálogo y contacto (públicos)**

| Método | Ruta | Respuestas |
|---|---|---|
| GET | `/api/productos?buscar&categoria&color&talla&precio_min&precio_max&disponible&orden&pagina&por_pagina` | 200, 400 |
| GET | `/api/productos/{slug}` | 200, 404 |
| GET | `/api/catalogo/filtros` | 200 |
| POST | `/api/consultas` | 201, 400, 429 |

`orden`: `novedades` (por defecto), `precio_asc`, `precio_desc`, `nombre`.
Cada talla de la ficha trae `variante_id`, `talla`, `stock` y `disponible`: el `variante_id` es lo que se envía al hacer un pedido.

**Área del cliente**

| Método | Ruta | Permiso | Respuestas |
|---|---|---|---|
| GET | `/api/perfil` | `perfil.propio` | 200, 401 |
| PATCH | `/api/perfil` | `perfil.propio` | 200, 400, 409 |
| GET | `/api/pedidos?estado&pagina` | `pedidos.propios` | 200 (solo los suyos) |
| POST | `/api/pedidos` | `pedidos.propios` | 201, 400, 409 sin stock |
| GET | `/api/pedidos/{codigo}` | `pedidos.propios` | 200, 404 (también si el pedido es de otro cliente) |
| POST | `/api/pedidos/{codigo}/cancelar` | `pedidos.propios` | 200, 404, 409 si su estado ya no permite cancelar |

Cuerpo de `POST /api/pedidos`:
```json
{
  "direccion_envio": "Calle Ficticia 1, 28001 Madrid",
  "notas": "Opcional",
  "lineas": [
    { "variante_id": 12, "cantidad": 2 },
    { "variante_id": 30, "cantidad": 1 }
  ]
}
```
- Entre 1 y 20 líneas, de 1 a 20 pares por línea y una línea por talla.
- Si se omite `direccion_envio`, se usa la del perfil del cliente.
- 409 sin stock: `detalles.sin_stock` lista cada talla con lo pedido y lo disponible.

**Área interna**

| Método | Ruta | Permiso | Respuestas |
|---|---|---|---|
| GET | `/api/gestion/resumen` | `informes.ver` | 200: pedidos por estado, unidades vendidas, modelos con menos stock |
| GET | `/api/gestion/informes/{nombre}` | `informes.ver` | 200, 404 |
| GET | `/api/gestion/pedidos?estado&buscar&pagina` | `pedidos.ver_todos` | 200 |
| GET | `/api/gestion/pedidos/{codigo}` | `pedidos.ver_todos` | 200, 404 |
| PATCH | `/api/gestion/pedidos/{codigo}/estado` | `pedidos.cambiar_estado` | 200, 400, 404, 409 transición no válida |
| GET | `/api/gestion/stock?bajo_minimo&buscar&pagina` | `stock.gestionar` | 200 |
| PATCH | `/api/gestion/variantes/{id}/stock` | `stock.gestionar` | 200, 400, 404, 409 si quedaría negativo |
| GET | `/api/gestion/productos?pagina` | `productos.gestionar` | 200 (incluye desactivados) |
| POST | `/api/gestion/productos` | `productos.gestionar` | 201, 400, 409 |
| PATCH | `/api/gestion/productos/{id}` | `productos.gestionar` | 200, 400, 404, 409 |
| DELETE | `/api/gestion/productos/{id}` | `productos.gestionar` | 204 (lo desactiva, no lo borra), 404 |
| GET | `/api/gestion/auditoria?tipo&entidad&pagina` | `auditoria.ver` | 200 |
| GET | `/api/gestion/exportar/pedidos?formato=csv\|json` | `datos.exportar` | 200 (descarga) |
| POST | `/api/gestion/importar/...` | `datos.exportar` | 201, 400 con los errores de cada fila |
| GET | `/api/usuarios?rol&pagina` | `usuarios.gestionar` | 200 |

Estados de un pedido: `pendiente → confirmado → preparacion → enviado → entregado`.
Se puede cancelar desde `pendiente` o `confirmado`; al cancelar se repone el stock una sola vez.

## 10. Páginas HTML

PHP sirve el esqueleto de cada página (cabecera, pie, `<meta name="csrf-token">`, CSS y JS) y el JavaScript
rellena el contenido con `fetch` a la API. Ningún dato de productos ni de pedidos se escribe a mano en el HTML.

| Ruta | Acceso | Contenido |
|---|---|---|
| `/` | Público | Landing |
| `/catalogo` | Público | Catálogo con filtros |
| `/producto/{slug}` | Público | Ficha, tallas y stock |
| `/login`, `/registro` | Público | Formularios de acceso |
| `/contacto` | Público | Formulario de consulta |
| `/cuenta` | Cliente | Perfil y pedidos propios |
| `/pedido` | Cliente | Carrito y confirmación del pedido |
| `/panel` | Admin y almacén | Área interna |

Las páginas privadas sin sesión redirigen a `/login`. Las de otro perfil muestran la página de error 403.
