# Verificación de ITurno AI

## Resultados ejecutados

Verificado el 1 de noviembre de 2026 con Node.js 24.18.0 y Chromium de Playwright. Las pruebas usan bases temporales y datos ficticios, sin modificar la base del negocio.

- `npm install`: completado; auditoría de npm con **0 vulnerabilidades conocidas** en ese momento.
- `npm test`: **13 pruebas aprobadas, 0 fallidas**.
- `npm run test:browser`: recorrido completo aprobado en Chromium de escritorio (1440 × 1000) y móvil (390 × 844), sin errores de JavaScript.
- Capturas de escritorio, móvil y panel generadas en `docs/screenshots/` y revisadas visualmente.
- `node --check` sobre servidor, módulos de frontend y servicios, y `bash -n` sobre scripts de instalación/actualización: sin errores.
- `scripts/admin.js`: probado en terminal interactiva y directorio temporal; pide contraseña oculta, verifica repetición y guarda la configuración correctamente.

En este entorno fue necesario permitir acceso al registro de npm y ejecutar el servidor HTTP y Chromium fuera del sandbox restringido. Un primer selector de la prueba de seguimiento se corrigió para incluir el botón real; la ejecución final pasó completa.

## Repetir pruebas

```bash
npm install
npm test
npx playwright install chromium
npm run test:browser
```

Playwright solo es una herramienta de desarrollo: el frontend sigue siendo HTML, CSS y JavaScript vanilla. Las pruebas no llaman a OpenAI ni necesitan API key. La prueba del adaptador OpenAI usa respuestas controladas para verificar contrato y manejo de errores.

## Suite de API

| Caso | Comprobación |
| --- | --- |
| A: audio | Pregunta de sistema operativo, solución sugerida, confirmación explícita, resolución guardada y cero tickets |
| B: encendido | Derivación, datos y consentimiento, ticket real, código, idempotencia, actualización, nota privada y seguimiento |
| C: rendimiento | Detección de presupuesto SSD, categoría comercial y prioridad baja |
| Mensajes repetidos | Mismo requestId devuelve la misma respuesta, no duplica mensajes; distinto contenido con mismo id produce 409 |
| Accesos | Conversación exige su token; otra conversación no puede leerla; admin exige sesión; logout la invalida |
| Datos inválidos | Mensaje vacío, contacto inválido, consentimiento falso, estado inexistente y origen ajeno son rechazados |
| SQL / contenido | Búsqueda con texto de inyección no altera consultas; contenido HTML se almacena como texto |
| Confirmación | «Sí, pero todavía no funciona» no cuenta como resolución |
| Error de IA | Devuelve 503 sin perder el mensaje; reintento con mismo id no lo duplica |
| Persistencia | Cierra servidor y conexión SQLite, crea otra aplicación sobre el mismo archivo y recupera ticket/conversación mediante API |
| Modelo no confiable | Una acción resolved sin solución previa y confirmación no cierra el caso |
| OpenAI | Contexto, schema estricto, clave en llamada de servidor, store:false y error HTTP del proveedor |
| Límites | Rate limit devuelve 429; CSP y nosniff están presentes |
| Backup | Backup con WAL activo, reapertura, integrity_check=ok y ticket conservado |

## Suite de navegador

1. Carga la home y verifica etiqueta demo.
2. Resuelve audio con los botones, recarga y recupera el caso cerrado.
3. Inicia nueva conversación, deriva hardware y envía formulario con consentimiento.
4. Introduce un nombre con HTML y comprueba que el dashboard lo muestra como texto sin crear scripts.
5. Ingresa al panel mediante el formulario real, abre ticket, cambia estado y guarda nota.
6. Consulta desde la home el estado actualizado y verifica que la nota privada no se filtra.
7. Registra presupuesto de SSD y verifica su aparición en la tabla.
8. Filtra historial por soluciones confirmadas y cierra sesión.
9. En móvil comprueba ausencia de scroll horizontal, interacción del chat y cambio a modo oscuro.

## Pruebas manuales para una presentación

- **Error de contacto:** intentar consultar un ticket con otro email. Debe fallar sin revelar datos del cliente.
- **Sugerida sin confirmar:** abandonar después de las instrucciones de audio. Debe aparecer sin confirmación y no incrementar resueltas por IA.
- **Audio no resuelto:** responder «Sigue sin sonido». Debe ofrecer derivación, sin afirmar que se resolvió.
- **Riesgo eléctrico:** indicar «Sale humo y olor a quemado». Debe advertir y derivar sin instrucciones para abrir componentes.
- **Persistencia:** registrar un ticket, reiniciar `npm start` y consultar con código/contacto. Debe seguir disponible.
- **Pérdida de conexión:** desconectar el servidor durante un envío y volver a iniciarlo. Usar reintento y verificar un único mensaje y ticket.
- **Filtros:** probar por estado, prioridad y nombre/código; limpiar para volver al listado.
- **Estados finales:** marcar resuelto y entregado; las métricas deben reflejar el cierre y el historial conservar los cambios.
- **Pantallas pequeñas:** probar teclado real, orientación horizontal, zoom, navegación con teclado y foco de diálogos en el teléfono objetivo del hackatón.

## Pendientes externos y límites de validación

- **No se hizo una llamada real a OpenAI**, porque no hay una API key configurada. Falta verificar permisos del modelo, cuota, latencia, facturación y calidad de conversaciones reales con una cuenta habilitada.
- Los archivos de **systemd, Apache y HTTPS son ejemplos**; no se instalaron ni probaron en un VPS. Validar rutas de Node, usuario de servicio, módulos, DNS, certificados y renovación en el servidor destino.
- No se ejecutaron pruebas en Safari, Firefox o dispositivos físicos ni pruebas de carga prolongadas.
- Los límites en memoria y el bloqueo por conversación están diseñados para un único proceso Node; no usar múltiples workers sin coordinación adicional.
- La prevención de prompt injection combina instrucciones y validación de operaciones; no implica que el texto generado por un modelo sea infalible. No tiene acceso a SQL, comandos, notas internas ni herramientas administrativas.
