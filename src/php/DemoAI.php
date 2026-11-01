<?php
declare(strict_types=1);
namespace ITurno;
function normalize(string $value): string { return strtr(mb_strtolower($value), ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']); }
function isConfirmation(string $value): bool {
    $t=normalize(trim($value));
    return !preg_match('/\b(no|sigue|todavia|aun|pero)\b/u',$t) && (bool)preg_match('/^(si\b|funciona\b|funciono\b|ya (funciona|anda|se escucha)|se (soluciono|resolvio|escucha)|listo\b|solucionado\b|resuelto\b|ahora (si|funciona|anda))/u',$t);
}
function demoReply(array $messages, array $previous=[]): array {
    $text=normalize($messages[array_key_last($messages)]['content']);
    $users=array_column(array_filter($messages,fn($m)=>$m['role']==='user'),'content');
    $all=normalize(implode(' ',$users));
    $scenario=$previous['scenario'] ?? (preg_match('/sonido|audio|escuch|parlante/',$all) ? 'audio' : (preg_match('/enciend|prende|alimentacion|quemad|humo/',$all) ? 'hardware' : (preg_match('/mejor|rendimiento|ssd|actualiz|lenta|lento|presupuesto/',$all) ? 'upgrade' : 'general')));
    $base=['scenario'=>$scenario,'category'=>'Consulta general','action'=>'continue','diagnosis'=>'','solution'=>'','summary'=>mb_substr(implode(' · ',$users),0,1800),'priority'=>'Media','suggestions'=>[]];
    if (preg_match('/humo|quemad|chispa|liquido|mojad/',$text)) return array_replace($base,['scenario'=>'hardware','category'=>'Problema de hardware','action'=>'escalate','priority'=>'Alta','diagnosis'=>'Posible daño eléctrico o por líquido. Requiere inspección presencial.','reply'=>'Por seguridad, dejá de usar el equipo. Si es seguro, desconectalo de la corriente sin tocar partes mojadas. No lo abras ni vuelvas a encenderlo. Necesita revisión de un técnico. Completá tus datos debajo para registrar la solicitud.']);
    if ($scenario==='audio') {
        $base['category']='Problema de software'; $base['diagnosis']='Posible dispositivo de salida de audio incorrecto o volumen silenciado.';
        if (($previous['action']??'')==='suggested' && isConfirmation($text)) return array_replace($base,['action'=>'resolved','solution'=>$previous['solution'],'reply'=>'¡Buenísimo! Registré tu confirmación y la solución aplicada. El caso quedó resuelto con asistencia automática, sin generar un ticket técnico.']);
        if (($previous['action']??'')==='suggested') return array_replace($base,['action'=>'escalate','reply'=>'Como el cambio de salida no lo resolvió, conviene revisar controladores y el dispositivo con un técnico. Completá tu nombre, contacto y equipo debajo; voy a adjuntar lo que ya probamos.']);
        if (empty($previous['audioAsked']) && !preg_match('/windows|mac|linux/',$text)) return array_replace($base,['audioAsked'=>true,'reply'=>'Vamos a revisar la salida de audio. ¿Usás Windows 10, Windows 11, macOS u otro sistema?','suggestions'=>['Windows 11','Windows 10','macOS']]);
        $solution=str_contains($text,'mac') ? 'Abrir Configuración del Sistema → Sonido → Salida, seleccionar los altavoces y revisar volumen y silencio.' : (str_contains($text,'linux') ? 'Abrir la configuración de Sonido → Salida, seleccionar los altavoces y revisar volumen y silencio.' : 'Abrir Configuración → Sistema → Sonido → Salida, elegir los altavoces correctos y revisar volumen y silencio.');
        return array_replace($base,['action'=>'suggested','solution'=>$solution,'reply'=>$solution.' Probá reproducir un audio. ¿Ahora se escucha?','suggestions'=>['Sí, ya funciona','Sigue sin sonido']]);
    }
    if ($scenario==='hardware') {
        $base['category']='Problema de hardware'; $base['diagnosis']='Equipo sin encendido; posible problema de alimentación. Diagnóstico pendiente de revisión física.';
        if (empty($previous['powerAsked'])) return array_replace($base,['powerAsked'=>true,'reply'=>'¿Se enciende alguna luz o se escucha un ventilador al presionar el botón? Podés comprobar el enchufe con otro dispositivo, sin abrir el equipo. Si hay olor a quemado o líquido, no vuelvas a conectarlo.','suggestions'=>['No enciende ninguna luz','Enciende una luz, pero no arranca']]);
        return array_replace($base,['action'=>'escalate','priority'=>'Alta','reply'=>'Con esos síntomas hace falta revisar la alimentación y los componentes en el taller. No abras la fuente ni el equipo. Completá tus datos debajo y registraré un ticket con este diagnóstico preliminar.']);
    }
    if ($scenario==='upgrade') {
        $base['category']='Solicitud de presupuesto'; $base['diagnosis']='Interés comercial en mejorar rendimiento; evaluar compatibilidad de SSD y memoria, con respaldo previo.';
        if (empty($previous['equipmentAsked'])) return array_replace($base,['equipmentAsked'=>true,'reply'=>'Una actualización a SSD puede mejorar los tiempos de inicio y apertura de programas. ¿Es una notebook o una PC de escritorio? Si conocés el modelo, contámelo también.','suggestions'=>['Notebook','PC de escritorio']]);
        return array_replace($base,['action'=>'escalate','priority'=>'Baja','reply'=>'Voy a registrar una solicitud de presupuesto para evaluar un SSD compatible. El técnico confirmará compatibilidad, respaldo de datos y precio antes de hacer cambios. Completá tus datos y el modelo del equipo debajo.']);
    }
    if (preg_match('/seguimiento|reparacion|estado.*ticket/',$text)) return array_replace($base,['category'=>'Seguimiento de reparación','reply'=>'Podés consultar el estado en «Seguir reparación», con el código y el contacto que usaste al registrar el ticket. Así protegemos tus datos.']);
    if (preg_match('/turno|reserv|llevar|contratar/',$text)) return array_replace($base,['category'=>'Solicitud de turno','action'=>'escalate','diagnosis'=>'Solicitud de coordinación de atención presencial; horario pendiente de confirmación humana.','reply'=>'Puedo registrar tu pedido de turno. Completá tus datos y el equipo debajo. Un técnico se contactará para confirmar disponibilidad; todavía no hay una reserva de horario.']);
    if (!empty($previous['generalAsked'])) return array_replace($base,['action'=>'escalate','category'=>preg_match('/wifi|internet|red/',$all) ? 'Redes y conectividad' : (preg_match('/virus|malware/',$all) ? 'Virus y seguridad' : 'Otros'),'diagnosis'=>'Consulta fuera de los escenarios guiados del modo demo. Requiere evaluación humana.','reply'=>'Este modo demo tiene guías de audio, encendido y mejora con SSD. Para tu consulta puedo registrar una solicitud real y derivarla a un técnico. Completá los datos debajo.']);
    return array_replace($base,['generalAsked'=>true,'reply'=>'Contame qué equipo usás y qué sucede exactamente. En esta demostración podemos probar falta de sonido, un equipo que no enciende o una mejora de rendimiento.','suggestions'=>['Mi computadora no tiene sonido','Mi equipo no enciende','Quiero mejorar mi computadora']]);
}
