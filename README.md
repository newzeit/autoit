# ITurno AI

Recepción virtual y soporte técnico para un negocio local de informática. Cada consulta queda registrada, las soluciones simples se confirman con el cliente y los problemas que requieren intervención humana se convierten en tickets con contexto.

## Inicio rápido

Requisitos: **Node.js 24 o superior**, npm y un directorio con permisos de escritura. Se usa `node:sqlite`, incluido en Node, sin compilar dependencias nativas.

```bash
npm install
cp .env.example .env
npm run admin:setup
npm start
```

Abrí **http://127.0.0.1:3000**. El panel está en **/admin.html**. El asistente funciona inmediatamente en demo, incluso si todavía no configuraste el administrador. No hay usuario con contraseña predeterminada: el comando de setup pide una contraseña oculta de al menos 12 caracteres y guarda únicamente su hash scrypt. Reiniciá el servidor después de configurarlo.

Alternativa: `bash scripts/install.sh` instala desde el lockfile y crea `.env` solo si no existe. El directorio del proyecto debe ser escribible por quien lo ejecuta. No requiere root.

## Funcionalidades

- Chat adaptable a móviles, contexto persistente, respuestas sugeridas, indicador de espera, reintentos y nueva conversación.
- Modo demo explícito: audio, falla de encendido y presupuesto para SSD.
- OpenAI desde el servidor, salida estructurada, clasificación en diez categorías, diagnóstico preliminar, resumen, prioridad y derivación.
- Soluciones sugeridas diferenciadas de confirmaciones explícitas. Resolver por IA no crea ticket.
- Formulario de derivación con nombre, teléfono/email, equipo, descripción y consentimiento. Al enviarlo, el ticket se crea automáticamente con la conversación adjunta.
- Ticket único por conversación y mensajes idempotentes por `requestId`.
- Seguimiento por código aleatorio y contacto registrado. Solo expone estado, categoría, fechas y cambios de estado, sin nombres, contactos ni notas internas.
- Dashboard autenticado: seis métricas, búsqueda, filtros, estados, prioridades, notas internas, historial y motivos frecuentes.
- Modo claro predeterminado y oscuro opcional. Sin fuentes, scripts ni recursos externos en el frontend.

## Arquitectura

Frontend: HTML5, CSS3 y módulos de JavaScript **vanilla**, sin framework ni compilación. Node.js se usa para el backend y herramientas de desarrollo; Playwright es únicamente una herramienta de pruebas.

Backend: Express 5, API REST y SQLite en `data/iturno.sqlite`. Las tablas de conversaciones, mensajes, clientes, solicitudes idempotentes, tickets, eventos, soluciones y sesiones son persistentes. SQL parametrizado, transacciones para operaciones compuestas y WAL. Se recomienda **un proceso Node**, almacenamiento local persistente y un VPS; no usar SQLite en sistemas de archivos de red o disco efímero.

```text
server.js                  API, autorización y coordinación de operaciones
src/db/index.js            esquema e inicialización SQLite
src/services/ai.js         Responses API y validación de respuesta
src/services/demo-ai.js    escenarios demo deterministas
src/security.js            tokens, hash scrypt y sesiones
public/                    HTML, CSS y JS vanilla
scripts/                   administrador, instalación, backup, actualización
deploy/                    ejemplos systemd y Apache
test/                      pruebas automáticas de API
```

La IA solo propone una acción. El backend comprueba el estado previo, la confirmación explícita y los datos requeridos antes de registrar una resolución o crear un ticket. Los datos de contacto se ingresan en un formulario y no se envían a OpenAI como parte del chat, salvo que el propio cliente los escriba en mensajes.

## Configuración

| Variable | Uso |
| --- | --- |
| `PORT` | Puerto, 3000 por defecto |
| `HOST` | Dirección de escucha, 127.0.0.1 por defecto; para dispositivos de la red usar 0.0.0.0 en una red de confianza |
| `AI_MODE` | `demo` por defecto, o `openai` |
| `OPENAI_API_KEY` | Clave privada, solo servidor |
| `OPENAI_MODEL` | Modelo configurable, ejemplo `gpt-4o-mini`; debe admitir salida estructurada |
| `AI_TIMEOUT_MS` | Tiempo máximo de respuesta, 25000 ms |
| `DB_PATH` | Archivo SQLite, `./data/iturno.sqlite` |
| `ADMIN_USER` / `ADMIN_PASSWORD_HASH` | Configurados por `npm run admin:setup` |
| `NODE_ENV` | `production` activa cookies Secure, requiere HTTPS para el panel |
| `TRUST_PROXY` | `1` solo con proxy de confianza en loopback |
| `BACKUP_DIR` | Directorio opcional para backups |

La aplicación carga `.env` mediante la funcionalidad nativa de Node. `.env`, bases, backups y credenciales no se incluyen en Git. Cambiar la contraseña con `npm run admin:setup` invalida las sesiones guardadas y requiere reinicio.

## Demo sin API key

Con `AI_MODE=demo` no se hacen llamadas a OpenAI. Una banda visible identifica la simulación y los tickets y conversaciones son reales. Desde las tarjetas o botones del chat podés probar:

1. «Mi computadora no tiene sonido» → «Windows 11» → «Sí, ya funciona». Queda resolución confirmada sin ticket.
2. Nueva conversación → «Mi equipo no enciende» → «No enciende ninguna luz» → completar datos. Queda un ticket de hardware.
3. Nueva conversación → «Quiero mejorar el rendimiento de mi computadora» → «Notebook» → completar datos. Queda una solicitud de presupuesto.

El signo `+` inicia otra consulta. La conversación activa se recupera al recargar en la misma pestaña mediante un token en `sessionStorage`. Al cerrar la pestaña se pierde ese acceso local; el historial del negocio permanece y el código más contacto permite seguir el ticket. Ver [DEMO.md](DEMO.md).

## OpenAI

Configurá `AI_MODE=openai` y `OPENAI_API_KEY` en `.env`, elegí un modelo habilitado en tu cuenta y reiniciá. Si falta la clave, el arranque falla con un mensaje claro; no se simula silenciosamente una respuesta real. La integración usa Responses API con JSON Schema estricto y `store:false`, según la [documentación oficial de Structured Outputs](https://developers.openai.com/api/docs/guides/structured-outputs). La validación local también controla enums y longitudes.

El historial completo se reenvía para conservar contexto (hasta 50 turnos). Se establece un timeout y se gestionan errores, respuestas incompletas y rechazos. Ante un fallo, el mensaje del cliente sigue guardado y se puede reintentar con el mismo identificador. No se crean tickets desde texto libre del modelo, ni existen herramientas que puedan ejecutar comandos. Los diagnósticos son orientativos y los turnos y precios requieren confirmación humana.

## API

Todas las mutaciones requieren JSON y cabecera `X-Requested-With: ITurno`. No se habilita CORS; las solicitudes con Origin deben provenir del mismo origen. La conversación necesita `Authorization: Bearer <accessToken>`; el token se entrega solo al crearla. Las sesiones administrativas se manejan con cookie HttpOnly/SameSite=Strict y expiran a las ocho horas.

| Endpoint | Acceso / función |
| --- | --- |
| `GET /api/health`, `GET /api/config` | Público, estado y configuración no sensible |
| `POST /api/conversations` | Crea conversación y devuelve token |
| `GET /api/conversations/:id` | Token propietario |
| `POST /api/chat` | Token; `conversationId`, `requestId` UUID y `message` |
| `POST /api/tickets` | Token; derivación previa, datos validados y `consent:true` |
| `GET /api/tickets/:id` | Token de la conversación propietaria |
| `POST /api/tracking` | Código y contacto, resultado reducido |
| `POST /api/admin/login` | Usuario y contraseña |
| `GET /api/admin/me`, `POST /api/admin/logout` | Sesión administrativa |
| `GET /api/admin/stats` | Métricas y categorías frecuentes |
| `GET /api/admin/tickets` | Filtros `status`, `priority`, `q`; últimos 200 resultados |
| `GET /api/admin/tickets/:id` | Detalles, mensajes y eventos |
| `PATCH /api/admin/tickets/:id` | `status`, `priority`, `note` opcionales |
| `GET /api/admin/conversations` | `kind=all|resolved|suggested|human`; últimas 200 |
| `GET /api/admin/conversations/:id` | Historial completo autorizado |

Los límites son por IP y se mantienen en memoria: 120 solicitudes/minuto generales, 20/minuto para conversaciones/chat/tickets y 10/15 minutos para login o seguimiento. Los mensajes admiten hasta 2000 caracteres; el JSON, 16 KB. Los tokens se guardan hasheados en SQLite. Los mensajes se muestran mediante `textContent`, protegidos además por CSP y Helmet.

## Pruebas

```bash
npm test
npm run test:browser
```

La segunda orden requiere instalar Chromium con `npx playwright install chromium`. Las pruebas usan bases temporales y credenciales generadas, sin tocar los datos del negocio. Ver alcance, resultados y comprobaciones manuales en [TESTING.md](TESTING.md).

## Despliegue en VPS Linux

No se aplicaron cambios a ningún VPS. Los archivos en `deploy/` son ejemplos para adaptar a la distribución y las rutas reales.

1. Instalá Node.js 24 o superior y Apache con módulos de proxy y TLS usando el método adecuado para tu distribución. Comprobá `node -v` y `command -v node`.
2. Creá un usuario de servicio sin privilegios `iturno`, copiá el proyecto a `/opt/iturno-ai` y asignale permisos. Ejecutá instalación y setup del administrador como ese usuario. Creá `data/` y `backups/`, ambos privados y escribibles por el servicio.
3. En `.env`: `HOST=127.0.0.1`, `PORT=3000`, `TRUST_PROXY=1`, `NODE_ENV=production`. El panel requiere HTTPS con esta configuración. Mantené `.env` con modo 600. Nunca publiques el puerto 3000 en el firewall.
4. Adaptá `deploy/iturno.service`, especialmente `ExecStart` al binario de Node real. Copialo a `/etc/systemd/system/iturno.service`. Luego `sudo systemctl daemon-reload` y `sudo systemctl enable --now iturno`. Revisá `journalctl -u iturno` y `/api/health`.
5. Adaptá `deploy/apache.conf` con el dominio real. En Debian/Ubuntu, activá `proxy`, `proxy_http`, `headers` y `ssl` con `a2enmod`; habilitá el sitio con `a2ensite`. En otras distribuciones ajustá módulos, rutas y logs. Ejecutá el comprobador de configuración de Apache antes de recargar.
6. Apuntá el DNS al VPS y abrí 80/443. Instalá Certbot con el método de tu distribución y emití un certificado para tu dominio (`certbot --apache -d TU_DOMINIO`), activando redirección HTTP a HTTPS. El bloque TLS debe establecer `X-Forwarded-Proto: https` y mantener las reglas de proxy. Verificá renovación con `certbot renew --dry-run`.
7. Probá login, creación de ticket y seguimiento desde HTTPS. Apache debe reemplazar `X-Forwarded-Proto`, no confiar en el valor del cliente. Si SELinux bloquea conexiones del proxy, configurá la política correspondiente a tu distribución.

## Actualización y backup

`npm run backup` usa la API de backup de SQLite y produce una copia consistente incluso con WAL activo. No copies solo el `.sqlite` mientras hay escrituras. Guardá copias fuera del VPS con acceso restringido y probá restauraciones periódicamente.

Después de revisar y descargar una nueva versión, ejecutá `bash scripts/update.sh`. Crea backup, instala dependencias del lockfile y corre pruebas; conserva `.env` y datos. Luego reiniciá el servicio y comprobá salud y flujos. No realiza `git pull` ni reinicios con privilegios automáticamente.

Para restaurar: detené el servicio, conservá una copia del estado actual, reemplazá el archivo configurado por un backup validado y retirá los archivos WAL/SHM antiguos únicamente con el servicio detenido. Ajustá propietario/permisos e iniciá. Verificá `PRAGMA integrity_check` con SQLite y probá el panel. No reemplaces una base en uso.

## Alcance del MVP

No incluye conexión directa a WhatsApp, notificaciones por email/SMS, calendario con cupos, cobros ni múltiples roles administrativos. Registra solicitudes de turno, pero el horario se coordina con un humano. La política de retención de datos y la operación de backups se deben definir para el negocio antes de usar datos reales. Las métricas no son promesas de ahorro: se calculan sobre registros reales de esta instalación.
