<?php
declare(strict_types=1);
require __DIR__.'/../src/php/bootstrap.php';
function verify(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
$directory=sys_get_temp_dir().'/iturno-http-'.bin2hex(random_bytes(6)); mkdir($directory,0700);
$password=ITurno\token();
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); if (!$socket) throw new RuntimeException('No se puede abrir un puerto local: '.$error);
$address=stream_socket_get_name($socket,false); fclose($socket);
$environment=array_merge(getenv(),['DB_PATH'=>$directory.'/db.sqlite','APP_ENV'=>'development','AI_MODE'=>'demo','ADMIN_USER'=>'admin','ADMIN_PASSWORD_HASH'=>ITurno\passwordHash($password)]);
$process=proc_open([PHP_BINARY,'-S',$address,'-t',ITurno\ROOT.'/public',ITurno\ROOT.'/router.php'],[0=>['pipe','r'],1=>['file',$directory.'/server.log','a'],2=>['file',$directory.'/server.log','a']],$pipes,ITurno\ROOT,$environment);
if (!is_resource($process)) throw new RuntimeException('No se pudo iniciar PHP');
$base='http://'.$address; $cookie='';
function request(string $method,string $path,mixed $body=null,?string $token=null,array $extra=[]): array {
    global $base,$cookie;
    $curl=curl_init($base.$path); $headers=['Content-Type: application/json','X-Requested-With: ITurno'];
    if ($token) $headers[]='Authorization: Bearer '.$token;
    if ($cookie) $headers[]='Cookie: '.$cookie;
    array_push($headers,...$extra); $received=[];
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>5,CURLOPT_HEADERFUNCTION=>function($c,$line)use(&$received){ if (str_contains($line,':')) { [$k,$v]=explode(':',$line,2); $received[strtolower($k)]=trim($v); } return strlen($line); }]);
    if ($body!==null) curl_setopt($curl,CURLOPT_POSTFIELDS,is_string($body)?$body:ITurno\json($body));
    $raw=curl_exec($curl); if ($raw===false) throw new RuntimeException(curl_error($curl));
    return ['status'=>curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'data'=>json_decode($raw,true),'raw'=>$raw,'headers'=>$received];
}
try {
    $ready=false; for ($i=0;$i<100;$i++) { $probe=@fsockopen(explode(':',$address)[0],(int)explode(':',$address)[1],$e,$m,0.05); if ($probe) { fclose($probe); $ready=true; break; } usleep(20000); }
    verify($ready,'El servidor no inició: '.file_get_contents($directory.'/server.log'));
    $health=request('GET','/api/health'); verify($health['status']===200 && $health['data']['mode']==='demo','Health'); verify(isset($health['headers']['content-security-policy']),'CSP');
    foreach (['/','/admin.html','/js/chat.js','/css/styles.css'] as $path) verify(request('GET',$path)['status']===200,'Archivo público '.$path);
    foreach (['/.env','/server.js','/src/php/bootstrap.php','/data/iturno.sqlite','/.htaccess'] as $path) verify(request('GET',$path)['status']===404,'Archivo privado '.$path);
    verify(request('GET','/api/admin/stats')['status']===401,'Protección admin');
    verify(request('POST','/api/conversations','{malformed')['status']===400,'JSON inválido');
    verify(request('POST','/api/conversations',str_repeat('x',16385))['status']===413,'Límite de cuerpo');
    verify(request('POST','/api/conversations',(object)[],null,['Origin: https://evil.example'])['status']===403,'Origen');
    $c=request('POST','/api/conversations',(object)[])['data']; $access=$c['accessToken'];
    $chat=fn($message)=>request('POST','/api/chat',['conversationId'=>$c['id'],'requestId'=>ITurno\uuid(),'message'=>$message],$access);
    verify($chat('No tengo sonido')['status']===200,'Chat pregunta'); verify($chat('Windows 11')['data']['status']==='suggested','Sugerencia'); verify($chat('Sí, ya funciona')['data']['status']==='resolved','Confirmación');
    $hardware=request('POST','/api/conversations',(object)[])['data'];
    $send=fn($message)=>request('POST','/api/chat',['conversationId'=>$hardware['id'],'requestId'=>ITurno\uuid(),'message'=>$message],$hardware['accessToken']);
    $send('Mi equipo no enciende'); verify($send('No enciende ninguna luz')['data']['status']==='awaiting_details','Derivación');
    $body=['conversationId'=>$hardware['id'],'name'=>'Cliente HTTP <script>alert(1)</script>','contact'=>'demo@example.com','equipment'=>'Notebook Demo','description'=>'No enciende ninguna luz','consent'=>true];
    $created=request('POST','/api/tickets',$body,$hardware['accessToken']); verify($created['status']===201,'Crear ticket'); $t=$created['data']['ticket'];
    verify(request('POST','/api/tickets',$body,$hardware['accessToken'])['data']['ticket']['id']===$t['id'],'Idempotencia ticket');
    $login=request('POST','/api/admin/login',['username'=>'admin','password'=>$password]); verify($login['status']===200,'Login'); $cookie=explode(';',$login['headers']['set-cookie'])[0];
    verify(str_contains($login['headers']['set-cookie'],'HttpOnly'),'Cookie HttpOnly'); verify(request('GET','/api/admin/stats')['data']['resolvedAI']===1,'Estadísticas');
    verify(request('PATCH','/api/admin/tickets/'.$t['id'],['status'=>'En reparación','note'=>'Nota privada HTTP'])['status']===200,'Actualizar ticket');
    $tracking=request('POST','/api/tracking',['code'=>$t['code'],'contact'=>'demo@example.com']); verify($tracking['data']['status']==='En reparación','Seguimiento'); verify(!str_contains($tracking['raw'],'Nota privada'),'Privacidad');
    verify(request('GET','/api/admin/tickets?q='.rawurlencode("' OR 1=1 --"))['data']===[],'SQL parametrizado');
    verify(request('GET','/api/admin/conversations?kind=resolved')['status']===200,'Historial');
    verify(request('POST','/api/admin/logout',(object)[])['status']===200,'Logout'); verify(request('GET','/api/admin/stats')['status']===401,'Sesión invalidada');
    echo "✓ HTTP real: archivos públicos/privados, errores, CSP, chat, tickets, login, estado, seguimiento e historial\n";
} finally { proc_terminate($process); proc_close($process); }
