# KALMA SNKRS PHP · Despliegue en el servidor del aula

**Versión de referencia:** rama `davidRojas`, commit `07c3f16` de [KALMA-SNKRS-PHP-version](https://github.com/DaRoCo7/KALMA-SNKRS-PHP-version). Se puede desplegar cualquier día. Las 03:00 son la hora de la copia diaria.

El resultado esperado es abrir **https://kalma.local** desde un segundo equipo y obtener tres comprobaciones correctas en el servidor. El proxy Nginx atiende HTTP y HTTPS, sirve CSS, JavaScript e imágenes, y envía las peticiones dinámicas a PHP/Apache. PostgreSQL conserva los datos en el volumen de Compose.

## 1. Preparar el servidor Linux

Ejecuta los bloques Bash en el servidor, con la cuenta del equipo autorizada para usar Docker. Reserva los puertos 80 y 443 y averigua la IP del servidor accesible desde el aula. Usa la zona horaria acordada para las copias.

```bash
whoami
timedatectl
ip -br -4 addr
sudo ss -ltnp '( sport = :80 or sport = :443 )'
docker info --format 'Motor Docker {{.ServerVersion}}'
docker compose version
docker buildx version
systemctl is-active cron
```

En Ubuntu 24.04, si faltan herramientas y tienes autorización para instalarlas:

```bash
sudo apt update && sudo apt install -y git docker.io docker-compose-v2 docker-buildx curl python3 mkcert cron ca-certificates
sudo systemctl enable --now docker cron
```

Si Docker devuelve `permission denied`, el administrador debe habilitar una cuenta de despliegue autorizada. El instalador de cron debe ejecutarse desde la cuenta que podrá acceder a Docker sin interacción. Si Apache u otro servicio ocupa el 80 o el 443, acuerda con el responsable cómo liberar los puertos. Confirma con él las reglas de red para HTTP/HTTPS y la restricción de SSH a la red de administración del aula.

## 2. Obtener la versión probada

En una instalación nueva, desde la carpeta donde guardarás el proyecto:

```bash
git clone --branch davidRojas https://github.com/DaRoCo7/KALMA-SNKRS-PHP-version.git
cd KALMA-SNKRS-PHP-version
git switch --detach 07c3f16
git log -1 --oneline
```

GitHub puede pedir una cuenta con acceso al repositorio. Si se usa un Git bundle, comprueba que contiene **07c3f16**. El bundle inicial del ensayo contenía **3d9ce39**, anterior a los últimos scripts.

Ejecuta los pasos siguientes desde la raíz del clon. Mantén esa carpeta después de instalar cron: la tarea guardará su ruta absoluta.

## 3. Configurar la base de datos

En el primer despliegue, crea el archivo privado desde la plantilla. Si ya existe, revisa la configuración con el responsable antes de cambiarla.

```bash
test -e .env || (umask 077 && cp .env.example .env)
chmod 600 .env
```

Para introducir la contraseña acordada por el equipo, ejecuta esta línea. La entrada queda oculta. El comando conserva las demás variables y escapa los caracteres especiales para Compose.

```bash
python3 -c 'from pathlib import Path; from getpass import getpass; import json,re,sys; p=Path(".env"); s=p.read_text(); k=r"(?m)^DB_PASSWORD=.*$"; len(re.findall(k,s))==1 or sys.exit("Revisa DB_PASSWORD en la plantilla"); v=getpass("Contraseña PostgreSQL del equipo: "); v or sys.exit("Contraseña vacía"); p.write_text(re.sub(k,lambda _: "DB_PASSWORD="+json.dumps(v.replace("$","$$"),ensure_ascii=False),s))'
docker compose config --quiet
```

En una instalación existente, utiliza la credencial vigente del volumen PostgreSQL. Guarda la contraseña por el canal privado del equipo.

## 4. Resolver kalma.local y preparar HTTPS

**En el servidor**, comprueba `getent ahostsv4 kalma.local`. Si el aula ya usa DNS para ese nombre, verifica que apunta al servidor. Para un servidor sin entrada previa, puedes añadir la resolución local:

```bash
printf '127.0.0.1 kalma.local\n' | sudo tee -a /etc/hosts
getent ahostsv4 kalma.local
```

Para una instalación nueva con CA local, ejecuta como usuario de despliegue:

```bash
mkdir -p docker/nginx/certs
TRUST_STORES=system mkcert -install
mkcert -cert-file docker/nginx/certs/kalma.local.pem -key-file docker/nginx/certs/kalma.local-key.pem kalma.local localhost 127.0.0.1
chmod 600 docker/nginx/certs/kalma.local-key.pem
```

Si el responsable ya ha preparado el certificado y la clave, usa esos archivos en las mismas rutas y configura la confianza en su CA. La clave privada del servidor y la de la CA son confidenciales.

## 5. Arrancar KALMA

```bash
export APP_VERSION=07c3f16
bash scripts/deploy.sh produccion
docker compose ps
bash scripts/comprobar.sh
```

El despliegue construye las imágenes, espera a PostgreSQL, aplica las migraciones pendientes, levanta PHP y Nginx y consulta la salud por HTTPS. Con un volumen nuevo se cargan el esquema y las semillas. Conserva el volumen en despliegues posteriores.

**Resultado esperado de la comprobación:**

```text
OK    Portada HTTPS: HTTP 200
OK    /health: PHP y base de datos disponibles
OK    PostgreSQL: SELECT 1
```

Para comprobar la carga inicial:

```bash
docker compose exec -T db sh -c 'psql -X -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT (SELECT count(*) FROM productos) AS productos, (SELECT count(*) FROM pedidos) AS pedidos;"'
```

En el ensayo, la versión de referencia arrancó con **21 productos y 24 pedidos**. Más adelante, esos recuentos pueden cambiar por el uso de la tienda.

## 6. Probar desde un segundo equipo del aula

En el equipo cliente, configura el DNS del aula o añade al archivo hosts una entrada con la **IP real del servidor**, por ejemplo `192.168.1.40 kalma.local` si esa es su dirección. En Windows, hosts está en `C:\Windows\System32\drivers\etc\hosts`; en Linux, en `/etc/hosts`.

Obtén el **certificado público** de la CA del servidor, `rootCA.pem`, desde la carpeta indicada por `mkcert -CAROOT`. Confirma su procedencia con el responsable e impórtalo en el almacén de confianza que usa el navegador del cliente. Mantén `rootCA-key.pem` en el servidor.

1. Abre `http://kalma.local/catalogo?talla=42` y comprueba que redirige a HTTPS conservando ruta y parámetros.
2. Abre **https://kalma.local** y guarda una captura con la URL y la tienda.
3. Abre **https://kalma.local/health** y guarda la respuesta. Se espera `{"app":"disponible","base_datos":"disponible"}`.

## 7. Crear, programar y recuperar una copia

Ejecuta desde la raíz del repo con la misma cuenta que administra el proyecto Docker:

```bash
bash scripts/backup.sh
tail -n 3 backups/backup.log
bash scripts/instalar-cron.sh
cat /etc/cron.d/kalma-backup
```

El script crea `backups/AAAAMMDD-HHMM/`, con el volcado, su índice, la configuración versionada, el commit, las imágenes y las sumas SHA-256. Conserva las cinco últimas copias completas. Para recuperar el servicio necesitarás también el repo y el aprovisionamiento privado de credenciales y TLS.

La regla diaria debe empezar por `0 3 * * *` y mostrar el usuario y la ruta reales. El servidor debe estar encendido a esa hora. Comprueba la ejecución en `backups/backup.log` y la salida de cron en `backups/cron.log`.

Para la demostración, usa el identificador **de la copia que acabas de crear**:

```bash
read -rp 'Identificador de copia (AAAAMMDD-HHMM): ' copia
bash scripts/restaurar.sh "$copia"
```

El destino por defecto es una base aislada. Para comparar el primer pedido de la tienda con el recuperado:

```bash
consulta="SELECT current_database(), id, md5(row_to_json(p)::text) AS huella FROM pedidos p ORDER BY id LIMIT 1;"
docker compose exec -T db sh -c 'exec psql -X -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "$1"' sh "$consulta"
docker compose exec -T db sh -c 'exec psql -X -U "$POSTGRES_USER" -d "$1" -c "$2"' sh "kalma_restauracion_${copia/-/_}" "$consulta"
```

Con los datos de demostración sin cambios, compara el ID y la huella en ambas filas. Guarda las salidas con los nombres de las bases.

## 8. Si algo falla

| Síntoma | Qué comprobar |
|---|---|
| GitHub rechaza el clon | Acceso a la rama del equipo mediante una cuenta autorizada o un bundle actualizado. |
| APT espera un bloqueo | Deja terminar las actualizaciones automáticas y reintenta la instalación. |
| El proxy falla al arrancar | Revisa los puertos con `sudo ss -ltnp`, las dos rutas de certificados y `docker compose logs --tail=60 proxy`. |
| Error de socket al cargar las semillas | Comprueba el commit y `docker compose logs --tail=80 db`. La versión de referencia espera a PostgreSQL por TCP. |
| HTTPS funciona en el servidor y falla en el cliente | Verifica IP, resolución de `kalma.local`, acceso al puerto 443 y confianza del navegador en la CA correcta. |
| La copia falla desde cron | Revisa `backups/cron.log`, la ruta en `/etc/cron.d/kalma-backup`, los permisos del usuario y el estado de Docker. |

## Evidencias que debe llevarse el equipo

En los ensayos se verificaron la respuesta HTTPS 200, el arranque desde un volumen nuevo, la migración conservando los 24 pedidos, la copia ejecutada por cron a las 00:43 y la recuperación aislada del pedido 1 con la misma huella. La VM quedó programada para la copia diaria a las 03:00.

**En clase:** anota fecha, commit, IP, usuario de despliegue y puerto publicado. Guarda los tres resultados de `comprobar.sh`, la captura desde el segundo equipo y la recuperación aislada. Documenta por separado las pruebas de persistencia al recrear el contenedor, incidencia y recuperación con tiempos, logs, recursos y reversión que efectivamente se ejecuten en el servidor.
