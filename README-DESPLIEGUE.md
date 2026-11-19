# KALMA SNKRS -- Documentación de despliegue

## 1. Objetivo y alcance

Este documento describe la configuración de contenedores, el acceso
HTTPS, la persistencia de PostgreSQL, las copias de seguridad, la
restauración y las comprobaciones del servicio de KALMA SNKRS.

Complementa al `README.md` principal. No lo sustituye ni modifica.

Las pruebas descritas como realizadas corresponden al entorno de
desarrollo utilizado en Windows con Docker Desktop y Git Bash. No se
considera validado un despliegue de producción: los scripts mantienen
bloqueadas las operaciones de producción hasta revisar y definir su
configuración definitiva.

## 2. Arquitectura

El archivo `compose.yaml` define tres servicios:

  -----------------------------------------------------------------------
  Servicio                Imagen / tecnología     Responsabilidad
  ----------------------- ----------------------- -----------------------
  `proxy`                 Nginx 1.27              Terminación HTTPS,
                                                  proxy inverso y entrega
                                                  de recursos estáticos

  `app`                   PHP 8.3 con Apache      Ejecución de la
                                                  aplicación

  `db`                    PostgreSQL 17           Almacenamiento de los
                                                  datos
  -----------------------------------------------------------------------

### Redes

-   `front`: conecta el proxy con la aplicación.
-   `back`: conecta la aplicación con PostgreSQL y está marcada como red
    interna.

PostgreSQL no publica un puerto en el host en la configuración
principal. El acceso desde la aplicación se realiza mediante el servicio
`db` en la red Docker.

### Persistencia

PostgreSQL almacena sus datos en el volumen `datos_postgres`, montado en
`/var/lib/postgresql/data`.

En el entorno de desarrollo, `compose.dev.yaml` declara el volumen como
externo y utiliza el nombre:

`kalma-snkrs-php_datos_postgres_nuevo`

El volumen externo debe existir antes de iniciar el entorno. Esta
configuración evita que Compose cree otro volumen con un nombre distinto
y arranque accidentalmente una base de datos vacía.

Los SQL montados en `/docker-entrypoint-initdb.d/` se ejecutan cuando
PostgreSQL inicializa un directorio de datos vacío; no se vuelven a
ejecutar automáticamente sobre un volumen ya inicializado.

## 3. Requisitos y archivos de configuración

Requisitos utilizados en el entorno de desarrollo:

-   Docker Desktop con Docker Compose.
-   Git y Git Bash para ejecutar los scripts Bash desde Windows.
-   Un archivo `.env` con las variables requeridas por Compose.
-   Certificado y clave TLS disponibles en `docker/nginx/certs/`.

El archivo `.env` se mantiene fuera del repositorio. No debe añadirse a
Git ni compartirse con contraseñas reales.

### Variables de base de datos

``` dotenv
DB_NAME=nombre_base_datos
DB_USER=usuario_base_datos
DB_PASSWORD=contraseña_segura
```

### Variables opcionales de publicación

``` dotenv
KALMA_PUERTO_HTTP=80
KALMA_PUERTO_HTTPS=443
KALMA_ORIGEN=https://kalma.local
```

Los puertos y el origen deben ajustarse al entorno real. En las pruebas
locales se utilizaron los puertos HTTP `8081` y HTTPS `8443`.

El repositorio incluye `.env.example` como referencia. Deben revisarse
sus valores antes de preparar un entorno nuevo.

## 4. Desarrollo con Docker Compose

La configuración de desarrollo combina `compose.yaml` y
`compose.dev.yaml`.

Validar la configuración:

``` bash
docker compose -f compose.yaml -f compose.dev.yaml config --quiet
```

Iniciar o reconstruir los servicios:

``` bash
docker compose -f compose.yaml -f compose.dev.yaml up -d --build
```

Consultar el estado:

``` bash
docker compose -f compose.yaml -f compose.dev.yaml ps
```

Consultar los registros:

``` bash
docker compose -f compose.yaml -f compose.dev.yaml logs
```

En el entorno local comprobado, la aplicación se abrió mediante:

`https://localhost:8443/`

## 5. HTTPS y Nginx

La configuración de Nginx está en `docker/nginx/default.conf`. El
`Dockerfile` de Nginx copia esa plantilla a
`/etc/nginx/templates/default.conf.template`; el archivo
`default.conf.bak` no forma parte de la imagen construida.

El proxy:

-   escucha HTTP y redirige a HTTPS usando el origen configurado;
-   termina las conexiones TLS;
-   reenvía las peticiones de la aplicación a `app:80`;
-   entrega directamente determinados recursos estáticos;
-   aplica límites de peticiones configurados en Nginx.

Los certificados locales utilizados durante las pruebas fueron:

-   `docker/nginx/certs/kalma.local.pem`
-   `docker/nginx/certs/kalma.local-key.pem`

Se comprobó que el certificado y la clave privada correspondían a la
misma pareja criptográfica comparando las huellas SHA-256 de sus claves
públicas.

El certificado inspeccionado era de desarrollo, emitido por una CA local
de mkcert, y contenía los nombres `kalma.local` y `localhost`, además de
las direcciones loopback. No debe presentarse como certificado público
de producción.

Para producción se debe aprovisionar un certificado válido para el
dominio definitivo y asegurar que los clientes confían en su autoridad
certificadora.

## 6. PostgreSQL y comprobaciones directas

La aplicación obtiene los parámetros de conexión mediante las variables
de entorno. En la red Docker utiliza el host `db` y el puerto `5432`.

Comprobar que PostgreSQL acepta conexiones:

``` bash
docker compose exec -T db pg_isready -U kalma -d kalma_snkrs
```

Ejecutar una consulta sencilla:

``` bash
docker compose exec -T db psql -U kalma -d kalma_snkrs -c "SELECT 1;"
```

Los comandos anteriores utilizan los valores del entorno de desarrollo
comprobado; deben adaptarse si cambian el usuario o el nombre de la base
de datos.

## 7. Copias de seguridad

El script disponible es `scripts/backup.sh`.

En el estado actual del proyecto:

-   el modo predeterminado es `desarrollo`;
-   se comprueba que Git no tenga cambios pendientes antes de crear la
    copia;
-   el modo `produccion` está bloqueado hasta verificar su
    configuración.

Ejecutar una copia de desarrollo:

``` bash
bash scripts/backup.sh desarrollo
```

La copia de prueba generada el 19 de noviembre de 2026 se guardó en
`backups/20261119-0403`. Incluía:

-   `base.dump`: volcado de PostgreSQL;
-   `configuracion.tar.gz`: archivos de configuración archivados desde
    el commit;
-   `commit.txt`: identificador del commit respaldado;
-   `indice.txt`: índice del volcado;
-   `imagenes.txt`: información de imágenes;
-   `SHA256SUMS`: manifiesto de integridad;
-   `.completa`: indicador de copia finalizada.

Comprobar la integridad desde la carpeta de la copia:

``` bash
sha256sum -c SHA256SUMS
```

En la copia de prueba, los hashes de `base.dump` y
`configuracion.tar.gz` se verificaron correctamente.

El archivo `.env` real y las claves TLS se aprovisionan por separado; no
forman parte del archivo de configuración archivado por el script. Deben
protegerse y respaldarse mediante un procedimiento seguro independiente.

## 8. Restauración

El script disponible es `scripts/restaurar.sh`.

En el estado actual:

-   la restauración predeterminada se realiza en una base de datos
    aislada;
-   el modo `--produccion` está bloqueado;
-   antes de restaurar se verifica el manifiesto SHA-256;
-   la base de ensayo se crea con un nombre derivado de la fecha de la
    copia;
-   si falla la restauración, el script intenta eliminar únicamente la
    base de ensayo creada por esa ejecución.

Ejemplo:

``` bash
bash scripts/restaurar.sh AAAAMMDD-HHMM
```

La prueba realizada con la copia `20261119-0403` creó la base:

`kalma_restauracion_20261119_0403`

La restauración terminó correctamente. Después se comparó el número de
registros de las 17 tablas entre la base original y la restaurada; los
recuentos coincidieron en todas ellas.

Esta prueba valida la restauración aislada de esa copia concreta. No
equivale a una prueba de recuperación de producción.

## 9. Comprobación del servicio

El script disponible es `scripts/comprobar.sh`.

Comprueba:

1.  que la portada HTTPS responda con HTTP 200;
2.  que `/health` responda correctamente y comunique que la aplicación y
    la base de datos están disponibles;
3.  que PostgreSQL pueda ejecutar `SELECT 1`.

El origen se puede indicar mediante `KALMA_URL`. Para la prueba local:

``` bash
export KALMA_URL=https://localhost:8443
bash scripts/comprobar.sh
```

Si se utiliza una CA específica, el script admite `KALMA_CA_CERT` para
indicar su certificado.

La comprobación de la respuesta JSON de `/health` se realiza con PHP
dentro del contenedor `app`, por lo que no depende de tener Python
instalado en Windows.

Resultado obtenido en la prueba local:

``` text
OK    Portada HTTPS: HTTP 200
OK    /health: PHP y base de datos disponibles
OK    PostgreSQL: SELECT 1
```

## 10. Scripts de despliegue

  Script                   Función
  ------------------------ -----------------------------
  `scripts/deploy.sh`      Despliegue de la aplicación
  `scripts/backup.sh`      Copias de seguridad
  `scripts/restaurar.sh`   Restauración de copias
  `scripts/comprobar.sh`   Comprobación del servicio

Los scripts Bash se pueden ejecutar desde Git Bash en Windows.

En la versión documentada, el despliegue de producción, el backup de
producción y la restauración de producción están bloqueados
expresamente. No se deben retirar esas protecciones hasta definir y
verificar el volumen, las variables, los certificados, los puertos, el
dominio y el procedimiento de recuperación del entorno definitivo.

## 11. Resultados verificados

En el entorno de desarrollo se verificaron los siguientes puntos:

-   Compose validó la combinación de los archivos principal y de
    desarrollo.
-   Los servicios `proxy`, `app` y `db` aparecieron en estado saludable.
-   La portada HTTPS respondió con HTTP 200.
-   `/health` informó de que la aplicación y la base de datos estaban
    disponibles.
-   PostgreSQL aceptó conexiones y ejecutó `SELECT 1`.
-   El certificado y su clave privada coincidieron criptográficamente.
-   Se creó una copia de seguridad completa.
-   Los hashes de los archivos respaldados se verificaron.
-   La copia se restauró en una base de datos aislada.
-   Los recuentos de registros coincidieron en las 17 tablas comparadas.

La prueba de restauración comparó estos recuentos:

  Tabla                        Registros
  -------------------------- -----------
  auditoria                          121
  categorias                           3
  clientes                            13
  colores                              6
  consultas                            3
  historial_estados_pedido            82
  limites_peticiones                   2
  linea_personalizacion               12
  lineas_pedido                       31
  marcas                               1
  movimientos_stock                  160
  opciones_personalizacion             8
  pedidos                             24
  productos                           21
  roles                                3
  usuarios                            16
  variantes                          189

## 12. Pendiente antes de producción

No se ha acreditado un despliegue completo en un servidor de producción.
Antes de habilitarlo se debe:

-   identificar y verificar el volumen PostgreSQL definitivo;
-   definir y proteger las variables y credenciales de producción;
-   configurar el dominio, los puertos y el certificado TLS definitivo;
-   comprobar las reglas de red y acceso;
-   definir la ubicación y retención de las copias;
-   probar la restauración en el entorno previsto;
-   ejecutar las comprobaciones del servicio tras el despliegue;
-   habilitar las operaciones de producción solo después de validar todo
    lo anterior.

Las pruebas locales descritas en este documento no sustituyen estas
comprobaciones.

------------------------------------------------------------------------

Fin de la documentación de despliegue de KALMA SNKRS.
