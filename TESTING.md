# Verificación · versión PHP

## Ejecutar sin Node.js ni dependencias

Requisitos: PHP 8.2+, PDO SQLite, SQLite3, cURL y mbstring.

```bash
php scripts/check.php
php test/run.php
php test/http.php
```

Las suites usan directorios temporales, credenciales generadas y bases separadas. No alteran datos del negocio ni llaman a OpenAI. La suite HTTP necesita permiso para abrir un puerto local y ejecutar un proceso PHP hijo.

## Resultados de la migración

Verificado el 1 de noviembre de 2026 con PHP 8.5.12:

- **15 pruebas PHP aprobadas, 0 fallidas.**
- **Suite HTTP real aprobada**, con un servidor PHP temporal.
- Lint PHP de archivos nuevos sin errores.
- Backup consistente de la base existente creado antes de retirar el backend anterior.

No se pudo realizar una nueva comprobación visual: el entorno de control no dispone de un navegador conectado. Las pruebas y capturas de la antigua versión no se cuentan como validación de este backend. Las llamadas reales a OpenAI y el despliegue Apache/PHP-FPM requieren validación externa.

## Suite de negocio y seguridad

| Caso | Resultado esperado |
| --- | --- |
| Audio | Pregunta de sistema, solución sugerida y confirmada; sin ticket |
| Hardware | Derivación, ticket único, historial y seguimiento |
| SSD | Solicitud de presupuesto con categoría y prioridad correctas |
| Idempotencia | Mismo requestId devuelve respuesta guardada; cambio de contenido devuelve 409 |
| Accesos | Token de otra conversación no permite leer datos; admin exige sesión |
| CSRF/origen | Sin cabecera requerida o con origen ajeno se rechaza la mutación |
| Validación | Contacto inválido, campos desconocidos, consentimiento falso y estado inexistente se rechazan |
| SQL | Búsqueda con texto de inyección no altera consultas; filtros y apóstrofes funcionan |
| Soluciones | Una negación no se considera confirmación; se diferencia historial sugerido |
| Error de IA | Se guarda el mensaje y se permite reintentar sin duplicarlo |
| Persistencia | Se cierra y reabre SQLite/aplicación y se recuperan tickets y conversaciones |
| Modelo no confiable | No puede cerrar una consulta sin solución previa y confirmación |
| OpenAI | Contrato de contexto/schema/store:false; respuestas incompletas y falta de clave se rechazan con transporte simulado |
| Límites | Persisten entre instancias PHP |
| Concurrencia | Un segundo bloqueo de la misma conversación devuelve 409 y se libera al terminar |
| Backup | Con WAL activo se conservan tickets y integrity_check=ok; permisos privados |
| Contraseñas | password_verify valida hash PHP y rechaza contraseña incorrecta; .env conserva `$` literalmente |

## Suite HTTP

Inicia un servidor PHP sin usar la configuración administrativa real y verifica:

- Home, admin, JS y CSS disponibles; .env, código interno, archivos de datos y .htaccess inaccesibles.
- API de salud, CSP, errores JSON, límite de tamaño y control de Origin.
- Audio resuelto y guardado.
- Hardware derivado y ticket idempotente.
- Login, cookie HttpOnly, estadísticas, cambio de estado y nota interna.
- Seguimiento actualizado sin filtrar notas.
- Búsqueda parametrizada, historial y cierre de sesión con invalidación.

## Revisión manual del navegador

Iniciá `php scripts/start.php` y seguí [DEMO.md](DEMO.md).

1. Probá los tres escenarios con botones y texto libre.
2. Recargá durante una conversación y después de una solución confirmada.
3. Completá datos y consentimiento; guardá el código y consultalo con el mismo contacto.
4. Entrá al panel, buscá por cliente/código, filtrá, cambiá estado y agregá una nota.
5. Comprobá que el seguimiento nunca muestra datos personales ni notas.
6. Probá vista móvil, teclado, foco en diálogos y modo oscuro.
7. Probá desconexión y reintento de un mensaje. Debe quedar un único registro.
8. Reiniciá PHP y verificá que los datos permanecen.

## Pendientes y límites

- Sin API key no se ejecutó una llamada real a OpenAI: verificar cuenta, modelo, cuota, latencia y calidad.
- No se instaló Apache/PHP-FPM ni HTTPS en un VPS: adaptar módulos, socket, permisos, SELinux y certificados.
- No se hicieron pruebas de carga, de múltiples servidores ni en dispositivos físicos/Safari/Firefox.
- Los bloqueos están diseñados para workers del mismo host y almacenamiento local; no coordinan múltiples VPS.
- La protección de operaciones no garantiza que todo texto generado por IA sea correcto. El modelo no tiene acceso a SQL, comandos o herramientas administrativas.
