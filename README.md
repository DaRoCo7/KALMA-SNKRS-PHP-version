# KALMA SNKRS · Sneaker Factory

Backend de una tienda online de zapatillas Nike exclusivas para particulares: catálogo con tallas y stock,
pedidos con personalización, área interna para administración y almacén, informes, auditoría y exportación.

Proyecto intermodular de 2.º DAW (XTART): **Desarrollo Web en Entorno Servidor** + **Despliegue de Aplicaciones Web**.

| | |
|---|---|
| Lenguaje | PHP 8.3 sin framework (MVC propio, Composer con autoload PSR-4) |
| Base de datos | PostgreSQL 17 (17 tablas) |
| Servidor | Apache dentro del contenedor `app`, detrás de un proxy Nginx |
| Contenedores | Docker Compose: `proxy`, `app` y `db` en dos redes |
| Rutas | 57: 45 de la API REST con JSON en `/api/*`, `/health` y 11 de las páginas HTML |

Documentación relacionada:
- [`docs/contrato-integracion.md`](docs/contrato-integracion.md): contrato entre la aplicación, el despliegue y el front (servicios, variables, `/health` y la especificación completa de la API).
- [`docs/diagrama-er.md`](docs/diagrama-er.md): modelo E/R conceptual, esquema relacional y justificación de cada tabla.
- [`postman/`](postman/): colección de pruebas con resultados esperados (176 peticiones, 375 comprobaciones).
- [`docs/pruebas/resultados-coleccion.md`](docs/pruebas/resultados-coleccion.md): resultados esperados y obtenidos de cada prueba.
- [`docs/pruebas/ejemplos-api.md`](docs/pruebas/ejemplos-api.md): ejemplos reales de petición y respuesta de la API.
- [`docs/KALMA_SNKRS_Memoria_Tecnica.pdf`](docs/KALMA_SNKRS_Memoria_Tecnica.pdf): memoria técnica (fuente en [`docs/memoria/`](docs/memoria/)).
- [`README-DESPLIEGUE.md`](README-DESPLIEGUE.md): contenedores, HTTPS, copias de seguridad, restauración y comprobaciones.
- [`docs/KALMA_Despliegue_Aula.md`](docs/KALMA_Despliegue_Aula.md): guía de despliegue en el servidor Ubuntu del aula.

---

## 1. Requisitos

- Docker Engine con el plugin Docker Compose (o Docker Desktop).
- Git (en Windows, Git Bash para los scripts `.sh`).
- `mkcert` u OpenSSL para crear el certificado HTTPS (OpenSSL viene con Git para Windows).
- Opcional: Postman o Node.js (para ejecutar la colección con `newman`).

No hace falta instalar PHP ni PostgreSQL: todo se ejecuta en contenedores.

## 2. Puesta en marcha

```bash
git clone <url-del-repositorio> kalma-snkrs
cd kalma-snkrs

# 1. Variables de entorno: copiar la plantilla y poner una contraseña para la base de datos
cp .env.example .env
#    editar .env y rellenar DB_PASSWORD

# 2. Certificado HTTPS: Nginx solo atiende por HTTPS y no arranca sin él.
mkdir -p docker/nginx/certs
#    a) Con mkcert (el navegador confía en él sin avisos):
mkcert -cert-file docker/nginx/certs/kalma.local.pem -key-file docker/nginx/certs/kalma.local-key.pem \
       kalma.local localhost 127.0.0.1
#    b) O autofirmado con OpenSSL (el navegador muestra un aviso que hay que aceptar):
MSYS_NO_PATHCONV=1 openssl req -x509 -newkey rsa:2048 -nodes -days 365 -subj "/CN=kalma.local" \
  -addext "subjectAltName=DNS:kalma.local,DNS:localhost,IP:127.0.0.1" \
  -keyout docker/nginx/certs/kalma.local-key.pem -out docker/nginx/certs/kalma.local.pem

# 3. Construir las imágenes y arrancar los tres servicios
docker compose up -d --build

# 4. Comprobar que todo está sano (los tres en "healthy")
docker compose ps
curl -k https://localhost/health
#    {"app":"disponible","base_datos":"disponible"}
```

La web queda en **https://localhost** y la API en **https://localhost/api**. `MSYS_NO_PATHCONV=1` solo hace
falta en Git Bash (evita que convierta `/CN=...` en una ruta de Windows); en Linux no molesta. La carpeta
`docker/nginx/certs/` no se sube al repositorio.

Nginx redirige cualquier petición `http://` a la dirección de `KALMA_ORIGEN` (por defecto `https://kalma.local`).
Para probar en el propio equipo, o si el 80 o el 443 ya están ocupados, se añade al `.env`:

```dotenv
KALMA_PUERTO_HTTP=8080
KALMA_PUERTO_HTTPS=8443
KALMA_ORIGEN=https://localhost:8443
```

y la web pasa a **https://localhost:8443**.

La primera vez, PostgreSQL crea el esquema y carga los datos de prueba automáticamente (ver el punto 4).

### Modo desarrollo

```bash
# Solo la primera vez: compose.dev.yaml usa un volumen externo con nombre fijo para no arrancar
# por error con una base de datos vacía. Con el volumen recién creado se cargan las semillas.
docker volume create kalma-snkrs-php_datos_postgres_nuevo

docker compose -f compose.yaml -f compose.dev.yaml up -d --build
```

La web queda en **https://localhost:8443**, publicada solo en el propio equipo (`http://localhost:8081` redirige
ahí). Monta `app/`, `config/` y `public/` dentro del contenedor (los cambios se ven al recargar, sin reconstruir),
muestra el motivo de los errores 500 y sube los límites de peticiones por IP para poder repetir las pruebas.

### Problemas habituales

| Síntoma | Causa y solución |
|---|---|
| El `proxy` se reinicia sin parar y la web no carga | Faltan `docker/nginx/certs/kalma.local.pem` o `kalma.local-key.pem`: crearlos (paso 2) |
| `external volume "kalma-snkrs-php_datos_postgres_nuevo" not found` | Modo desarrollo sin el volumen: `docker volume create kalma-snkrs-php_datos_postgres_nuevo` |
| `up --build` falla en Windows con `additional privileges requested` | La terminal está en `c:\...` en minúscula (VS Code la abre así): `cd C:\...` con la C en mayúscula y repetir |
| El login falla en Postman o con `curl` | Se usa `http://`: Nginx responde 301 y el `POST` redirigido llega como `GET`. Usar siempre la dirección `https://` |
| `429 Too Many Requests` | Límite de peticiones: esperar un minuto (Nginx) o 15 minutos (login por cuenta) |

### Comandos habituales

| Comando | Qué hace |
|---|---|
| `docker compose up -d --build` | Construye la imagen y arranca (o actualiza) los servicios |
| `docker compose down` | Para y borra los contenedores. **Los datos se conservan** en el volumen |
| `docker compose down -v` | Para y borra también el volumen: la base de datos se recrea desde cero al volver a arrancar |
| `docker compose logs -f app` | Log de la aplicación (una línea JSON por evento) |
| `docker compose logs -f proxy` | Accesos y errores de Nginx |
| `docker compose exec db psql -U kalma -d kalma_snkrs` | Consola SQL (usuario y base de `.env`) |

## 3. Variables de entorno

En el archivo `.env` (plantilla en [`.env.example`](.env.example), sin contraseñas):

| Variable | Obligatoria | Por defecto | Uso |
|---|---|---|---|
| `DB_NAME` | Sí | — | Nombre de la base de datos |
| `DB_USER` | Sí | — | Usuario de PostgreSQL |
| `DB_PASSWORD` | Sí | — | Contraseña de PostgreSQL |
| `KALMA_PUERTO_HTTP` | No | `80` | Puerto publicado para HTTP (solo redirige a HTTPS) |
| `KALMA_PUERTO_HTTPS` | No | `443` | Puerto publicado para HTTPS |
| `KALMA_ORIGEN` | No | `https://kalma.local` | Dirección pública a la que Nginx redirige las peticiones HTTP |
| `APP_VERSION` | No | `local` | Etiqueta de las imágenes; `scripts/deploy.sh` la exige para saber qué versión despliega |

Variables que lee la aplicación PHP. `compose.yaml` no las pasa, así que en el despliegue normal se usan los
valores por defecto; `compose.dev.yaml` las cambia para desarrollo:

| Variable | Por defecto | En desarrollo | Uso |
|---|---|---|---|
| `APP_ENTORNO` | `produccion` | `desarrollo` | `desarrollo` añade el motivo de los errores 500 a la respuesta |
| `LIMITE_LOGIN_IP` | `20` | `1000` | Intentos de login por IP cada 15 minutos |
| `LIMITE_REGISTRO_IP` | `5` | `1000` | Registros por IP cada hora |
| `LIMITE_CONSULTA_IP` | `5` | `1000` | Consultas del formulario de contacto por IP cada hora |

`DB_HOST` y `DB_PORT` los fija `compose.yaml` (`db:5432`). El archivo `.env` no se sube al repositorio
ni entra en la imagen.

## 4. Base de datos

- Esquema: [`database/schema.sql`](database/schema.sql). Datos de prueba: [`database/seeds/`](database/seeds/).
- Con el volumen vacío, PostgreSQL ejecuta en orden `01_schema.sql`, `02_usuarios.sql`, `03_catalogo.sql`
  y `04_pedidos.sql` (los monta `compose.yaml` en `/docker-entrypoint-initdb.d/`).
- Las semillas calculan el stock, los totales y el historial a partir de unas pocas filas, y al final
  comprueban que todo cuadra: si algo no cuadra, la carga se aborta y el contenedor no arranca.
- Para volver a los datos iniciales: `docker compose down -v && docker compose up -d`.

**Datos de prueba** (todos ficticios): 13 clientes (uno desactivado), 14 zapatillas Nike reales de 12 modelos
con 126 tallas, 8 opciones de personalización (una desactivada) y 24 pedidos en los 6 estados, 7 de ellos personalizados.

### Usuarios de prueba

Contraseña de todas las cuentas: **`Kalma2026!`**

| Email | Rol | Qué puede hacer |
|---|---|---|
| `admin@kalma.test` | Administrador | Todo: productos, albaranes, confirmar y cancelar pedidos, informes, auditoría, exportación, consultas |
| `almacen@kalma.test` | Almacén | Stock, consulta de los albaranes cargados, logística de los pedidos (preparación, envío y entrega) e informes de stock para administración |
| `cliente@kalma.test` | Cliente | Sus pedidos y su perfil |

Pedidos preparados para la demostración:

| Pedido | Cliente | Para qué sirve |
|---|---|---|
| `SF-2026-000021` | cliente@kalma.test | Pendiente y personalizado: la clienta lo puede cancelar y el stock se repone |
| `SF-2026-000013` | cliente@kalma.test | Enviado: cancelarlo da 409 |
| `SF-2026-000022` | otro cliente | La clienta no lo puede ver ni cancelar (404 y registro en auditoría: IDOR) |

## 5. Arquitectura

```
Navegador ──► proxy (Nginx) ──red front──► app (PHP 8.3 + Apache) ──red back──► db (PostgreSQL 17)
              puerto publicado                                                   puerto no publicado
```

Dentro de la aplicación, cada petición sigue siempre el mismo camino:

```
public/index.php (front controller)
  └─► Router (config/rutas.php y config/rutas/*.php)
        └─► Middlewares: sesión → CSRF → [límite de peticiones] → [Autenticado] → [RequierePermiso]
              └─► Controlador (lee la petición, construye la respuesta)
                    └─► Servicio (reglas de negocio, transacciones, auditoría)
                          └─► Repositorio (solo SQL con PDO y consultas preparadas)
                                └─► PostgreSQL
```

| Carpeta | Contenido |
|---|---|
| `public/` | `index.php` (única entrada), CSS e imágenes |
| `app/Nucleo/` | Router, Peticion, Respuesta, gestión de errores, logs, conexión, validación (también los ids de la ruta, `?buscar` y la talla), paginación, fechas ISO (`Formato`) y CSV para Excel (`Csv`) |
| `app/Middlewares/` | Sesión, CSRF, autenticación, permisos y límite de peticiones |
| `app/Seguridad/` | Matriz de permisos por rol, sesión y limitador |
| `app/Controladores/Api/` | API JSON (`Gestion/` para el área interna) |
| `app/Controladores/Web/` | Páginas HTML |
| `app/Servicios/` | Reglas de negocio; `PresentacionPedido` y `PresentacionProducto` dan forma al JSON |
| `app/Repositorios/` | Acceso a datos |
| `app/vistas/` | Plantillas HTML |
| `config/` | Rutas de la aplicación (una tabla por módulo en `config/rutas/`) |
| `database/` | Esquema y datos de prueba |

### Recorrido completo de una petición

Ejemplo: la clienta crea un pedido de dos pares de Air Force 1 con la caja edición KALMA.

1. **Navegador → proxy.** `POST /api/pedidos` con la cookie de sesión `KALMA_SESION`, la cabecera `X-CSRF-Token`
   y el cuerpo `{"lineas":[{"variante_id":1,"cantidad":2,"personalizacion":[{"opcion_id":1}]}]}`.
   Nginx aplica su límite de peticiones por IP, añade `X-Real-IP` y la pasa al contenedor `app`.
2. **Front controller y router.** `public/index.php` crea la `Peticion` y el `Router` encuentra la ruta en
   `config/rutas.php`.
3. **Middlewares.** Se recupera la sesión, se comprueba el token CSRF, que hay sesión (`Autenticado`) y que el rol
   tiene el permiso `pedidos.propios` (`RequierePermiso`). Si algo falla: 403 o 401 y, si es un acceso denegado,
   queda en la auditoría.
4. **Controlador.** `PedidoController::crear()` pasa el usuario de la sesión y el cuerpo JSON al servicio.
5. **Servicio.** `PedidoClienteServicio::crear()` comprueba que la cuenta sea de cliente y `CreacionPedido::crear()`
   valida la forma de los datos (`LineasPedido::validar()`) y abre una transacción en la que:
   - bloquea las tallas con `SELECT ... FOR UPDATE` (dos compras simultáneas no venden la misma unidad);
   - comprueba stock, personalización y precio con los datos del catálogo, nunca con los del navegador;
   - inserta el pedido, sus líneas y su personalización, descuenta el stock dejando su movimiento,
     calcula el total, guarda el primer estado del historial y registra la auditoría.
6. **Base de datos.** Si cualquier paso falla, `ROLLBACK`: no queda nada a medias. El importe de cada línea lo
   calcula la propia BD (columna generada) y el `CHECK (stock >= 0)` es la última defensa contra la sobreventa.
7. **Respuesta.** `201 Created` con `Location: /api/pedidos/SF-2026-000025` y el pedido en JSON
   (`{"datos": {...}}`). Si no hay stock: `409` con la talla y las unidades disponibles.

Se puede comprobar en la BD:

```sql
SELECT codigo, estado, personalizado, total FROM pedidos ORDER BY id DESC LIMIT 1;
SELECT tipo, cantidad, stock_resultante, motivo FROM movimientos_stock ORDER BY id DESC LIMIT 2;
SELECT tipo_evento, resultado, detalle FROM auditoria ORDER BY id DESC LIMIT 1;
```

## 6. API

Formato común:
- Éxito: `{"datos": ...}`; los listados añaden `"paginacion": {"pagina", "por_pagina", "total", "paginas"}`.
- Error: `{"error": {"codigo": 409, "mensaje": "...", "detalles": {...}}}`. En un 400, `detalles.errores` trae el
  motivo de cada campo.
- Toda petición `POST`, `PATCH` o `DELETE` lleva la cabecera `X-CSRF-Token` (se obtiene en `GET /api/sesion`
  y cambia al iniciar sesión).

| Grupo | Rutas | Permiso |
|---|---|---|
| Sistema y sesión | `GET /health` · `GET /api` · `GET /api/sesion` · `POST /api/registro` · `POST /api/login` · `POST /api/logout` | Público / sesión |
| Catálogo y contacto | `GET /api/productos` (búsqueda, filtros, orden y paginación) · `GET /api/productos/{slug}` · `GET /api/catalogo/filtros` · `POST /api/consultas` | Público |
| Cliente | `GET` y `PATCH /api/perfil` · `GET` y `POST /api/pedidos` · `GET /api/pedidos/{codigo}` · `POST /api/pedidos/{codigo}/cancelar` | Cliente |
| Pedidos (interno) | `GET /api/gestion/pedidos` · `GET /api/gestion/pedidos/{codigo}` · `PATCH /api/gestion/pedidos/{codigo}/estado` | Almacén y admin |
| Stock | `GET /api/gestion/stock` · `PATCH /api/gestion/variantes/{id}/stock` · `GET /api/gestion/variantes/{id}/movimientos` | Almacén y admin |
| Albaranes cargados | `GET /api/gestion/albaranes` · `GET /api/gestion/albaranes/detalle?referencia=` | Almacén y admin |
| Catálogo interno | `GET`, `POST /api/gestion/productos` · `GET`, `PATCH`, `DELETE /api/gestion/productos/{id}` · `POST /api/gestion/albaranes` | Admin |
| Informes y control | `GET /api/gestion/resumen` · `GET /api/gestion/informes/{nombre}` · `GET /api/gestion/auditoria` · `GET /api/gestion/exportar/pedidos` · `GET`, `PATCH /api/gestion/consultas` · `GET /api/usuarios` | Admin |
| Informes de stock | `GET /api/gestion/informes-stock/vista-previa` (y `/csv`) · `GET`, `POST /api/gestion/informes-stock` · `GET /api/gestion/informes-stock/{id}` (y `/csv`) · `PATCH /api/gestion/informes-stock/{id}/revisado` | Almacén los envía; admin los revisa |

La especificación de cada ruta (parámetros, cuerpo, permisos y códigos de respuesta) está en el
[punto 9 del contrato](docs/contrato-integracion.md#9-contrato-de-la-api-para-el-front) y cada una tiene
ejemplos de petición y respuesta en la colección de Postman.

### Reglas de negocio principales

| Regla | Dónde |
|---|---|
| Estados: `pendiente → confirmado → preparacion → enviado → entregado`; cancelar solo desde pendiente o confirmado, reponiendo el stock una vez | `EstadosPedido::TRANSICIONES` |
| El almacén lleva la logística; confirmar el pago y cancelar desde gestión son de administración | `EstadosPedido`, `GestionPedidoServicio`, `Permisos` |
| Pasar a enviado exige un código de seguimiento único | `GestionPedidoServicio`, `EstadosPedido` |
| Personalización por línea con recargo por par: una opción de cada tipo, solo opciones activas, el bordado solo en modelos personalizables (no Air Max ni Jordan) y con hasta 12 letras | `LineasPedido`, tabla `opciones_personalizacion` |
| Precios, recargos y totales los calcula el servidor | `LineasPedido::preparar()` y columna generada `importe` |
| El albarán CSV suma stock y crea tallas o zapatillas nuevas; con una fila mal se rechaza entero | `AlbaranServicio` |
| Un producto no se borra: se desactiva (los pedidos antiguos lo referencian) | `GestionProductoServicio` |

## 7. Seguridad

| Amenaza | Medida |
|---|---|
| Inyección SQL | Consultas preparadas reales (`PDO::ATTR_EMULATE_PREPARES = false`); los filtros y la ordenación eligen entre fragmentos fijos |
| XSS | Las vistas escapan todo con `e()` (`htmlspecialchars`); la API devuelve JSON con `X-Content-Type-Options: nosniff` |
| CSRF | Token por sesión obligatorio en toda petición que modifica datos; cookie `SameSite=Lax` |
| Sesión | Cookie HttpOnly (y Secure con HTTPS), id regenerado al iniciar sesión, caducidad a los 30 minutos sin actividad |
| Contraseñas | `password_hash` (bcrypt) y tiempo de respuesta igual exista o no el email |
| Fuerza bruta | Límite por IP en Nginx y en PHP. Por cuenta, 5 intentos cada 15 minutos desde una misma IP (el sexto es un `429` aunque la contraseña sea buena), así que quien conozca un email no puede dejar fuera a su dueño, que entra desde su IP. Si la cuenta acumula 10 fallos desde varias IP, cada IP tiene un solo intento hasta que pasen los 15 minutos. Todo queda en la auditoría |
| Accesos indebidos | Matriz de permisos por rol en el servidor; el pedido de otro cliente responde 404 (IDOR) y queda en auditoría |
| Errores | Nunca se muestran trazas: el cliente recibe un mensaje y una referencia que se busca en el log |
| Exportación CSV (pedidos e informes de stock) | Las celdas que empiezan por `=`, `+`, `-` o `@` se neutralizan (inyección de fórmulas en Excel) |
| Datos personales | No se guarda el DNI; la auditoría y los logs nunca llevan contraseñas, tokens ni valores personales |

## 8. Auditoría y logs

- **Auditoría** (tabla `auditoria`, consultable en `GET /api/gestion/auditoria`): logins, registros, accesos denegados,
  pedidos creados y cancelados, cambios de estado, ajustes de stock, albaranes, cambios de productos y de perfil,
  consultas y exportaciones. Cada evento guarda usuario, fecha, tipo, entidad, resultado (`exito`, `error` o
  `denegado`), un detalle sin datos sensibles y la IP real del cliente.
- **Informes de stock**: también viven en `auditoria`, sin tabla propia. Enviar uno es un evento `informe_stock.enviar`
  con la foto de las filas en el detalle (su id es el número del informe); revisarlo, un evento `informe_stock.revisar`
  que apunta a ese id. Pendiente = enviado y sin revisión.
- **Log de la aplicación**: `docker compose logs app`, una línea JSON por evento con fecha, nivel y referencia.
- **Movimientos de stock**: cada unidad que entra o sale queda en `movimientos_stock` con su motivo y el stock
  resultante (`GET /api/gestion/variantes/{id}/movimientos`).

## 9. Pruebas

La colección [`postman/KALMA-SNKRS.postman_collection.json`](postman/KALMA-SNKRS.postman_collection.json) prueba
toda la API, incluidos los errores y los usuarios sin permiso. Cada carpeta inicia y cierra su propia sesión, y el
token CSRF lo añade un script de la colección.

1. Arrancar en modo desarrollo (punto 2): sus límites por IP permiten repetir la colección.
2. Importar la colección y el entorno `postman/KALMA-SNKRS.postman_environment.json` (`base_url = https://localhost:8443`).
3. Con un certificado autofirmado, desactivar en Postman *Settings → General → SSL certificate verification*.
4. Ejecutar la colección completa (*Run collection*).

Desde la terminal, con Node.js (`--insecure` acepta el certificado autofirmado):

```bash
npx newman run postman/KALMA-SNKRS.postman_collection.json -e postman/KALMA-SNKRS.postman_environment.json --insecure
```

La `base_url` tiene que ser la dirección `https://`: con `http://` Nginx responde 301 y los `POST` redirigidos
llegan como `GET`, así que el login falla.

Resultado esperado con la base de datos recién creada: **176 peticiones de la colección (294 peticiones HTTP, contando
las que leen el token CSRF) y 375 comprobaciones sin fallos**. Los resultados obtenidos de cada prueba están en
[`docs/pruebas/resultados-coleccion.md`](docs/pruebas/resultados-coleccion.md).

Hay que tener en cuenta dos cosas:
- La colección inicia sesión muchas veces y Nginx limita el login a 30 peticiones por minuto e IP: si se lanza dos
  veces seguidas, la segunda recibe `429`. Basta con esperar un minuto.
- En modo producción el formulario de contacto admite 5 consultas por IP y hora; para repetir las pruebas, usar el
  modo desarrollo.

### Pruebas obligatorias de la defensa

| Prueba | Cómo hacerla |
|---|---|
| Registro, login, acceso denegado y operación autorizada de empleado | Carpetas 2 y 8: la clienta pide `/api/usuarios` (403) y el almacén avanza un pedido |
| Pedido con varias líneas: importe, stock y persistencia | Carpeta 6: crear el pedido y comprobar el total, el stock de la ficha y las consultas SQL del punto 5 |
| Cantidad no válida, pedido sin stock y pedido de otro cliente | Carpeta 6: 400 por línea, 409 con la talla que falta y 404 en `SF-2026-000022` |
| Búsqueda filtrada, informe real y exportación | Carpetas 4 y 9: `/api/productos?buscar=dunk&talla=40`, `/api/gestion/informes/ventas-por-modelo` y `/api/gestion/exportar/pedidos?formato=csv` |
| Inspeccionar una petición, su código, su respuesta y su auditoría | Cualquier petición en Postman y después `GET /api/gestion/auditoria` como admin |

## 10. Decisiones de alcance

- **Clientes particulares.** KALMA vende zapatillas exclusivas a particulares, así que no hay clubes ni equipos.
  Tampoco se pide el DNI: no hace falta para vender ni para enviar, y no guardarlo reduce los datos personales.
- **Personalización de producto exclusivo** en lugar de equipaciones: caja, nombre bordado, cordones de repuesto
  y certificado de autenticidad.
- **Las fotos** siguen una convención (`public/img/productos/<código>.webp`) en lugar de subirse desde el panel,
  así no hace falta un volumen para archivos subidos.
- **`codigo_marca`** es el código de estilo real de Nike (`CW2288-111`); **`rfid_tienda`** es la etiqueta RFID que
  pone KALMA en cada talla. Son cosas distintas (ver [`docs/diagrama-er.md`](docs/diagrama-er.md)).
- **Límite de login por cuenta e IP.** Antes el límite contaba los intentos de la cuenta viniera de donde viniera,
  y bastaba con conocer un email para bloquear a su dueño 15 minutos. Ahora el bloqueo duro va por cuenta + IP
  y se consume antes de comprobar la contraseña, en una sola sentencia atómica. No se dejó entrar siempre a la
  contraseña buena porque la diferencia entre un `429` y un `200` le diría al atacante cuál es.

## 11. Mejoras futuras

- **Verificación de cuentas por correo (SMTP, enlace de confirmación).** Hoy una cuenta se puede usar en cuanto se
  registra. Harían falta un servidor SMTP configurado por variables de entorno, un token aleatorio de un solo uso
  con caducidad (guardado como hash), una ruta que lo confirme y que el login rechace las cuentas que todavía no
  lo hayan confirmado.
