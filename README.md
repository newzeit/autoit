# ITurno AI · PHP

Recepción virtual para un servicio técnico informático: atención inmediata, soluciones simples confirmadas por el cliente y tickets con contexto para los técnicos.

**Backend PHP 8.2+, SQLite y cURL. Frontend HTML5, CSS3 y JavaScript vanilla.** No requiere Node.js, npm, Composer, frameworks ni compilación.

## Instalación local

Requisitos: PHP 8.2 o superior con `pdo_sqlite`, `sqlite3`, `curl` y `mbstring`, y permisos de escritura en el directorio de datos.

```bash
php scripts/check.php
php scripts/install.php
php scripts/admin.php
php scripts/start.php
```

Abrí **http://127.0.0.1:3000** y **/admin.html** para el panel. El instalador crea `.env` solo si no existe e inicializa SQLite. El asistente funciona sin configurar administrador; el panel necesita el alta interactiva, con contraseña oculta. No hay contraseña predeterminada. El servidor incorporado de PHP sirve para desarrollo y demostración; en producción usá Apache y PHP-FPM.

También podés iniciar directamente:

```bash
php -S 127.0.0.1:3000 -t public router.php
```

## Migración desde la versión anterior

Se mantiene el esquema de SQLite y las rutas API; los clientes, conversaciones, tickets, códigos e historiales existentes siguen siendo compatibles. No se borran ni recrean las tablas. La migración agrega una tabla de límites de solicitudes y un directorio privado de bloqueos.

1. Detené el servidor anterior para evitar escritores simultáneos de versiones distintas.
2. Conservá el archivo SQLite y `.env`. Hacé backup antes de actualizar: `php scripts/backup.php`.
3. En `.env` usá `APP_ENV=development` para HTTP local o `APP_ENV=production` para HTTPS. La configuración antigua `NODE_ENV` ya no se usa; podés retirarla junto con `TRUST_PROXY`.
4. Ejecutá `php scripts/admin.php`: el hash scrypt de la versión anterior requiere volver a configurar la contraseña con el formato seguro de PHP. El comando invalida las sesiones existentes y preserva las otras variables.
5. Ejecutá las pruebas y arrancá PHP. En Apache, reemplazá el proxy al antiguo servicio por un DocumentRoot dirigido a `public/` y un manejador PHP-FPM.

El backup previo realizado durante esta migración quedó en `backups/`, excluido de Git. No se modificó la configuración administrativa ni se restableció una contraseña sin tu intervención.

## Funcionalidades

- Chat con contexto persistente, respuestas sugeridas, indicador de espera, reintento y recuperación al recargar.
- Modo demo explícito: falta de sonido, equipo que no enciende y presupuesto de SSD.
- OpenAI desde PHP mediante cURL, diagnóstico, diez categorías, resúmenes, prioridades y derivación.
- Soluciones sugeridas diferenciadas de confirmaciones. Una solución confirmada no genera ticket.
- Derivación mediante formulario validado, consentimiento y ticket automático con conversación adjunta.
- Un ticket por conversación y mensajes idempotentes por `requestId`; bloqueos compartidos entre procesos PHP del mismo servidor.
- Seguimiento privado mediante código aleatorio y contacto registrado, sin exposición de notas internas ni datos personales.
- Dashboard con autenticación, seis métricas, búsqueda, filtros, estados, prioridades, notas e historial.
- Diseño adaptable, modo claro predeterminado y oscuro opcional. Frontend sin peticiones a CDNs ni fuentes externas.

## Arquitectura

```text
public/index.html          atención al cliente
public/admin.html          panel administrativo
public/index.php           entrada HTTP de la API
public/.htaccess           rutas y cabeceras Apache
public/css/                estilos
public/js/                 módulos vanilla del navegador
router.php                 enrutador del servidor de desarrollo PHP
src/php/bootstrap.php      configuración, validación y utilidades
src/php/Application.php    API, autorización y lógica de negocio
src/php/Database.php       SQLite, transacciones y bloqueos
src/php/schema.sql         esquema compatible con los datos existentes
src/php/DemoAI.php          escenarios guiados
src/php/AI.php              integración OpenAI y validación
src/php/support-prompt.txt  instrucciones de soporte
scripts/                   instalación, administrador, backup, actualización
test/                      pruebas PHP
deploy/apache.conf         ejemplo de despliegue
```

SQLite usa WAL, claves foráneas y SQL parametrizado. Las operaciones compuestas usan transacciones. El acceso a OpenAI no mantiene una transacción de escritura abierta. Los bloqueos con `flock` coordinan workers PHP-FPM sobre el mismo disco local. No usar esta arquitectura con múltiples servidores, discos efímeros ni archivos compartidos por red sin cambiar la coordinación y persistencia.

La IA solo propone acciones; el backend valida datos, estado y confirmación antes de cerrar casos o crear tickets. Los datos de contacto del formulario no se envían a OpenAI, salvo que el cliente los escriba por su cuenta en el chat.

## Configuración

`.env` se lee desde PHP, sin interpolar variables ni expandir los `$` de los hashes. Las variables de entorno del proceso tienen precedencia. Nunca ubiques `.env`, SQLite o backups dentro de `public/`.

| Variable | Uso |
| --- | --- |
| `APP_ENV` | `development` para HTTP local; `production` activa cookies Secure y requiere HTTPS |
| `HOST`, `PORT` | Servidor local de desarrollo: 127.0.0.1 y 3000 |
| `AI_MODE` | `demo` por defecto o `openai` |
| `OPENAI_API_KEY` | Clave privada del servidor |
| `OPENAI_MODEL` | Modelo con salida estructurada, ejemplo configurable `gpt-4o-mini` |
| `AI_TIMEOUT_MS` | Timeout del proveedor, por defecto 25000 ms, máximo 60000 |
| `DB_PATH` | SQLite, `./data/iturno.sqlite` por defecto |
| `BACKUP_DIR` | `./backups` por defecto |
| `ADMIN_USER`, `ADMIN_PASSWORD_HASH` | Generados por `php scripts/admin.php` |

Las rutas relativas se resuelven desde la raíz del proyecto, incluso si un comando se ejecuta desde otro directorio. El alta administrativa guarda un hash Argon2id si está disponible, con bcrypt como alternativa, y deja `.env` con permisos 600. La contraseña se pide oculta en una terminal Linux/macOS, entre 12 y 72 bytes. Cambiarla invalida las sesiones.

## Modo demo

`AI_MODE=demo` no realiza llamadas externas. La interfaz indica que las respuestas son simuladas y que los registros son reales.

1. «No tengo sonido» → «Windows 11» → «Sí, ya funciona»: resolución confirmada sin ticket.
2. Nueva conversación → «Mi equipo no enciende» → «No enciende ninguna luz»: derivación; completar datos y registrar ticket.
3. Nueva conversación → «Quiero mejorar el rendimiento de mi computadora» → «Notebook»: solicitud de presupuesto de SSD.

El botón `+` inicia una consulta nueva. `sessionStorage` conserva el token de la conversación en esa pestaña. Si se cierra, se pierde ese acceso local, pero el historial del negocio sigue guardado y el ticket se puede seguir mediante código/contacto. Ver [DEMO.md](DEMO.md).

## OpenAI

Configurá `AI_MODE=openai` y la clave en `.env`. El backend usa la Responses API con `store:false`, contexto de conversación y JSON Schema estricto conforme a la [documentación oficial de OpenAI](https://developers.openai.com/api/docs/guides/structured-outputs). PHP valida también las categorías, acciones y longitudes.

La URL de OpenAI es fija y solo se llama desde el servidor, con verificación TLS de cURL activa y timeout. Se gestionan errores HTTP, salidas incompletas, JSON inválido y rechazos. Ante un error, el mensaje del cliente permanece registrado para reintentarlo con el mismo identificador. No hay cambio silencioso a demo si falla OpenAI o falta la clave.

Se conservan hasta 50 turnos. El asistente no tiene herramientas para ejecutar SQL, comandos o acciones administrativas. Los diagnósticos son preliminares; precios, compatibilidad y horarios requieren confirmación humana.

## API y seguridad

Las rutas conservan el contrato del frontend. Las mutaciones requieren JSON y `X-Requested-With: ITurno`. Se verifica Origin si está presente, no se habilita CORS y las sesiones usan cookies HttpOnly, SameSite=Strict y Secure en producción. Los tokens se almacenan como SHA-256; la expiración de sesión es de ocho horas.

| Endpoint | Función / acceso |
| --- | --- |
| `GET /api/health`, `GET /api/config` | Salud y configuración pública no sensible |
| `POST /api/conversations` | Crear conversación y token |
| `GET /api/conversations/:id` | Token propietario |
| `POST /api/chat` | Token; `conversationId`, `requestId` UUID, `message` |
| `POST /api/tickets` | Token, derivación previa, datos validados y `consent:true` |
| `GET /api/tickets/:id` | Token de la conversación propietaria |
| `POST /api/tracking` | Código y contacto; resultado limitado a estado, categoría, fechas y cambios de estado |
| `POST /api/admin/login` | Usuario y contraseña |
| `GET /api/admin/me`, `POST /api/admin/logout` | Sesión administrativa |
| `GET /api/admin/stats` | Métricas |
| `GET /api/admin/tickets` | Filtros `status`, `priority`, `q`; últimos 200 |
| `GET /api/admin/tickets/:id` | Detalle, eventos y conversación |
| `PATCH /api/admin/tickets/:id` | `status`, `priority`, `note` opcionales |
| `GET /api/admin/conversations` | `kind=all|resolved|suggested|human`; últimas 200 |
| `GET /api/admin/conversations/:id` | Conversación completa |

Límites persistentes por IP: 120 solicitudes/minuto generales, 20/minuto para creación/chat/tickets y 10/15 minutos para login/seguimiento. Se guarda un hash del identificador de límite, no la IP en texto. El JSON admite 16 KB y el mensaje 2000 caracteres. CSP, cabeceras defensivas y renderizado con `textContent` reducen XSS. No publicar la raíz del proyecto como DocumentRoot.

## Pruebas sin dependencias

```bash
php test/run.php
php test/http.php
```

La primera suite prueba negocio, seguridad, persistencia, bloqueo entre instancias, adaptador OpenAI y backup. La segunda inicia un servidor PHP temporal y prueba la API HTTP real y los archivos públicos/privados. No usan la base del negocio ni hacen llamadas externas. Ver [TESTING.md](TESTING.md).

## Apache, PHP-FPM y HTTPS

1. Instalá PHP 8.2+, las extensiones requeridas y Apache/PHP-FPM según tu distribución. `php scripts/check.php` verifica CLI; verificá también las extensiones de PHP-FPM, que puede cargar otro `php.ini`.
2. Copiá el proyecto a `/opt/iturno-ai`. Ejecutá instalación y alta de administrador. El usuario de PHP-FPM necesita leer la aplicación y escribir en `data/` y `backups/`, sin permisos de escritura generales sobre el código. `.env` debe ser legible solo por el propietario o el grupo de servicio apropiado; ajustá su propiedad al servicio.
3. Adaptá `deploy/apache.conf`: dominio, rutas, logs y socket PHP-FPM real. En Debian/Ubuntu suele ser `/run/php/php8.x-fpm.sock`; en Fedora/RHEL puede ser `/run/php-fpm/www.sock`. Activá `rewrite`, `headers`, `proxy` y `proxy_fcgi` según corresponda. Si usás mod_php, reemplazá el bloque SetHandler por la configuración de tu distribución.
4. El DocumentRoot debe ser **/opt/iturno-ai/public**, con `.htaccess` habilitado. No hace falta un servicio de aplicación persistente ni un proxy al puerto 3000.
5. Ejecutá el comprobador de Apache, habilitá el sitio y recargá. En servidores SELinux, ajustá contextos para lectura/escritura y conexiones salientes HTTPS de PHP usando la política de la distribución.
6. Configurá DNS y puertos 80/443. Instalá Certbot para Apache, emití certificado para tu dominio, conservá las reglas de DocumentRoot/PHP en el bloque TLS y activá redirección a HTTPS. Verificá renovación con `certbot renew --dry-run`.
7. Usá `APP_ENV=production` y comprobá login, ticket y seguimiento desde HTTPS. Si hay un proxy TLS adicional, configurá HTTPS en Apache/FPM desde una fuente de confianza; la aplicación no confía ciegamente en `X-Forwarded-Proto`.

No se instaló Apache ni se modificó ningún VPS durante la migración.

## Backup y actualización

```bash
php scripts/backup.php
php scripts/update.php
```

El backup usa la API [SQLite3::backup de PHP](https://www.php.net/manual/en/sqlite3.backup.php), compatible con WAL activo, valida integridad y genera un archivo privado. No copies solo `.sqlite` mientras hay escrituras. Programá copias fuera del VPS con acceso restringido.

Después de revisar y descargar una versión nueva, el script de actualización hace backup y corre la suite PHP. No modifica Git, `.env` ni reinicia servicios con privilegios. Si usás OPcache, recargá PHP-FPM tras actualizar y comprobá `/api/health`.

Para restaurar: detené el acceso a la aplicación y sus workers, conservá el estado actual, reemplazá SQLite con un backup validado y retirás WAL/SHM antiguos solo con todos los escritores detenidos. Revisá propietario/permisos y `PRAGMA integrity_check` antes de volver a habilitar el sitio. No reemplaces una base en uso.

## Alcance

No incluye WhatsApp, notificaciones, agenda con cupos, cobros ni múltiples roles. Los pedidos de turno requieren coordinación humana. Las métricas se calculan sobre esta instalación; no son promesas de ahorro. Antes de usar datos reales, definí retención y operación de backups para el negocio.
