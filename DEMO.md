# Demostración de ITurno AI · 3 minutos

## Preparación

- Instalá dependencias, dejá `AI_MODE=demo` y configurá el administrador con `php scripts/admin.php`.
- Ejecutá `php scripts/start.php`. Abrí la home y el panel en pestañas separadas. Usá datos ficticios; no hace falta API key ni conexión a OpenAI.
- El modo demo está rotulado. Las conversaciones, tickets y métricas se guardan realmente en SQLite. No hay datos precargados ni métricas inventadas.
- Si ya hiciste pruebas, las métricas serán acumuladas. Para una demostración aislada usá otro `DB_PATH` antes de iniciar y de configurar el administrador; no borres la base del negocio.

## Guion

**0:00–0:25 · El problema**

«Un local de soporte pierde consultas cuando el técnico está ocupado. ITurno AI recibe al cliente de inmediato y conserva cada conversación. Puede resolver problemas simples o dejar un pedido completo para una persona.» Mostrá la home y la banda de demo.

**0:25–1:00 · A: resolución automática**

Seleccioná «No tengo sonido», luego «Windows 11». Mostrá las instrucciones para elegir la salida de audio. Indicá que aún es una solución sugerida. Elegí «Sí, ya funciona». Aparece la confirmación de cierre, sin ticket.

Resultado: conversación, diagnóstico, solución y confirmación guardados. Aumenta la métrica de resolución automática. Si no confirma, no se cuenta como resuelta.

**1:00–1:45 · B: intervención humana**

Usá `+` para una nueva consulta y confirmá. Escribí «Mi equipo no enciende» y elegí «No enciende ninguna luz». El asistente explica por qué requiere revisión sin indicar abrir el equipo. Completá nombre ficticio, `demo@example.com`, equipo y consentimiento. Registrá la solicitud.

Resultado: ticket real con código de seguimiento, prioridad y conversación adjunta. Mostrá el código y el seguimiento con el mismo contacto.

**1:45–2:15 · C: oportunidad comercial**

Nueva consulta: «Quiero mejorar el rendimiento de mi computadora» → «Notebook». Se identifica una posible mejora a SSD. Completá el formulario de presupuesto.

Resultado: ticket en categoría «Solicitud de presupuesto», sin inventar precio ni prometer compatibilidad. El técnico valida y cotiza.

**2:15–2:50 · El equipo trabaja con contexto**

En el panel, mostrá las métricas y abrí el ticket de hardware. Se ven nombre, contacto, equipo, diagnóstico preliminar, resumen y conversación. Cambiá a «En diagnóstico» y agregá una nota interna. La consulta pública de estado refleja el cambio y no muestra la nota. En el historial filtrá las soluciones confirmadas para ver el caso de audio.

**2:50–3:00 · Valor para el negocio**

«Una respuesta inmediata, menos consultas perdidas y técnicos que reciben el contexto completo. Podemos medir qué resolvió la asistencia y qué necesita una persona.»

## Criterios para mostrar honestamente

- La demo es una simulación guiada, no una respuesta de OpenAI.
- OpenAI es un modo separado y requiere clave del servidor.
- Una solicitud de turno no es un horario reservado.
- Un diagnóstico preliminar no reemplaza la evaluación del técnico.
- Recargar mantiene la conversación en la pestaña; reiniciar PHP conserva los datos en SQLite.

## Capturas

Las capturas en `docs/screenshots/` muestran la interfaz. Para actualizar la demostración, capturá la home, el chat y el panel desde el servidor PHP, usando datos ficticios.
