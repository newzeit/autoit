const normalize = text => text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();
export function isConfirmation(text) {
  const t = normalize(text).trim();
  return !/\b(no|sigue|todavia|aun|pero)\b/.test(t) && /^(si\b|funciona\b|funciono\b|ya (funciona|anda|se escucha)|se (soluciono|resolvio|escucha)|listo\b|solucionado\b|resuelto\b|ahora (si|funciona|anda))/.test(t);
}
export function demoReply(messages, previous = {}) {
  const text = normalize(messages.at(-1).content);
  const all = normalize(messages.filter(m => m.role === 'user').map(m => m.content).join(' '));
  let scenario = previous.scenario;
  if (!scenario) scenario = /sonido|audio|escuch|parlante/.test(all) ? 'audio' : /enciend|enciende|prende|alimentacion|quemad|humo/.test(all) ? 'hardware' : /mejor|rendimiento|ssd|actualiz|lenta|lento|presupuesto/.test(all) ? 'upgrade' : 'general';
  const base = { scenario, category: 'Consulta general', action: 'continue', diagnosis: '', solution: '', summary: messages.filter(m => m.role === 'user').map(m => m.content).join(' · ').slice(0, 1800), priority: 'Media', suggestions: [] };
  if (/humo|quemad|chispa|liquido|mojad/.test(text)) return { ...base, scenario: 'hardware', category: 'Problema de hardware', action: 'escalate', priority: 'Alta', diagnosis: 'Posible daño eléctrico o por líquido. Requiere inspección presencial.', reply: 'Por seguridad, dejá de usar el equipo. Si es seguro, desconectalo de la corriente sin tocar partes mojadas. No lo abras ni vuelvas a encenderlo. Necesita revisión de un técnico. Completá tus datos debajo para registrar la solicitud.' };
  if (scenario === 'audio') {
    base.category = 'Problema de software';
    base.diagnosis = 'Posible dispositivo de salida de audio incorrecto o volumen silenciado.';
    if (previous.action === 'suggested' && isConfirmation(text)) return { ...base, action: 'resolved', solution: previous.solution, reply: '¡Buenísimo! Registré tu confirmación y la solución aplicada. El caso quedó resuelto con asistencia automática, sin generar un ticket técnico.' };
    if (previous.action === 'suggested') return { ...base, action: 'escalate', reply: 'Como el cambio de salida no lo resolvió, conviene revisar controladores y el dispositivo con un técnico. Completá tu nombre, contacto y equipo debajo; voy a adjuntar lo que ya probamos.' };
    if (!previous.audioAsked && !/windows|mac|linux/.test(text)) return { ...base, audioAsked: true, reply: 'Vamos a revisar la salida de audio. ¿Usás Windows 10, Windows 11, macOS u otro sistema?', suggestions: ['Windows 11', 'Windows 10', 'macOS'] };
    const mac = /mac/.test(text);
    const solution = mac ? 'Abrir Configuración del Sistema → Sonido → Salida, seleccionar los altavoces y revisar volumen y silencio.' : 'Abrir Configuración → Sistema → Sonido → Salida, elegir los altavoces correctos y revisar volumen y silencio.';
    return { ...base, action: 'suggested', solution, reply: `${solution} Probá reproducir un audio. ¿Ahora se escucha?`, suggestions: ['Sí, ya funciona', 'Sigue sin sonido'] };
  }
  if (scenario === 'hardware') {
    base.category = 'Problema de hardware';
    base.diagnosis = 'Equipo sin encendido; posible problema de alimentación. Diagnóstico pendiente de revisión física.';
    if (!previous.powerAsked) return { ...base, powerAsked: true, reply: '¿Se enciende alguna luz o se escucha un ventilador al presionar el botón? Podés comprobar el enchufe con otro dispositivo, sin abrir el equipo. Si hay olor a quemado o líquido, no vuelvas a conectarlo.', suggestions: ['No enciende ninguna luz', 'Enciende una luz, pero no arranca'] };
    return { ...base, action: 'escalate', priority: 'Alta', reply: 'Con esos síntomas hace falta revisar la alimentación y los componentes en el taller. No abras la fuente ni el equipo. Completá tus datos debajo y registraré un ticket con este diagnóstico preliminar.' };
  }
  if (scenario === 'upgrade') {
    base.category = 'Solicitud de presupuesto';
    base.diagnosis = 'Interés comercial en mejorar rendimiento; evaluar compatibilidad de SSD y memoria, con respaldo previo.';
    if (!previous.equipmentAsked) return { ...base, equipmentAsked: true, reply: 'Una actualización a SSD puede mejorar los tiempos de inicio y apertura de programas. ¿Es una notebook o una PC de escritorio? Si conocés el modelo, contámelo también.', suggestions: ['Notebook', 'PC de escritorio'] };
    return { ...base, action: 'escalate', priority: 'Baja', reply: 'Voy a registrar una solicitud de presupuesto para evaluar un SSD compatible. El técnico confirmará compatibilidad, respaldo de datos y precio antes de hacer cambios. Completá tus datos y el modelo del equipo debajo.' };
  }
  if (/seguimiento|reparacion|estado.*ticket/.test(text)) return { ...base, category: 'Seguimiento de reparación', reply: 'Podés consultar el estado en «Seguir reparación», con el código y el contacto que usaste al registrar el ticket. Así protegemos tus datos.' };
  if (/turno|reserv|llevar|contratar/.test(text)) return { ...base, category: 'Solicitud de turno', action: 'escalate', diagnosis: 'Solicitud de coordinación de atención presencial; horario pendiente de confirmación humana.', reply: 'Puedo registrar tu pedido de turno. Completá tus datos y el equipo debajo. Un técnico se contactará para confirmar disponibilidad; todavía no hay una reserva de horario.' };
  if (previous.generalAsked) return { ...base, action: 'escalate', category: /wifi|internet|red/.test(all) ? 'Redes y conectividad' : /virus|malware/.test(all) ? 'Virus y seguridad' : 'Otros', diagnosis: 'Consulta fuera de los escenarios guiados del modo demo. Requiere evaluación humana.', reply: 'Este modo demo tiene guías de audio, encendido y mejora con SSD. Para tu consulta puedo registrar una solicitud real y derivarla a un técnico. Completá los datos debajo.' };
  return { ...base, generalAsked: true, reply: 'Contame qué equipo usás y qué sucede exactamente. En esta demostración podemos probar falta de sonido, un equipo que no enciende o una mejora de rendimiento.', suggestions: ['Mi computadora no tiene sonido', 'Mi equipo no enciende', 'Quiero mejorar mi computadora'] };
}
