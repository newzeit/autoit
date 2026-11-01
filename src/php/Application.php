<?php
declare(strict_types=1);
namespace ITurno;
final class Response {
    public function __construct(public mixed $data, public int $status=200, public array $headers=[]) {}
}
final class Application {
    private \Closure $reply;
    public function __construct(public Database $db, public array $config, ?callable $reply=null) {
        $this->reply=$reply ? \Closure::fromCallable($reply) : (new AI($config))->reply(...);
    }
    public function handle(string $method, string $path, array $body=[], array $headers=[], array $query=[], array $cookies=[], string $ip='127.0.0.1', bool $https=false): Response {
        $headers=array_change_key_case($headers,CASE_LOWER);
        try {
            if (in_array($method,['POST','PATCH','DELETE'],true)) {
                ensure(strtolower(trim(explode(';',$headers['content-type']??'')[0]))==='application/json',415,'Se requiere application/json');
                ensure(($headers['x-requested-with']??'')==='ITurno',403,'Solicitud no autorizada');
                if (isset($headers['origin'])) ensure($headers['origin']===($https?'https':'http').'://'.($headers['host']??''),403,'Origen no autorizado');
            }
            $this->rate('general',$ip,120,60);
            if ($path==='/api/admin/login' || $path==='/api/tracking') $this->rate('restricted',$ip,10,900);
            if ($method==='POST' && in_array($path,['/api/chat','/api/conversations','/api/tickets'],true)) $this->rate('chat',$ip,20,60);
            return $this->dispatch($method,$path,$body,$headers,$query,$cookies);
        } catch (HttpError $e) { return new Response(['error'=>$e->getMessage()],$e->status); }
        catch (\Throwable $e) { error_log('ITurno operation failed: '.get_class($e)); return new Response(['error'=>'No pudimos completar la operación'],500); }
    }
    private function rate(string $scope,string $ip,int $limit,int $window): void {
        $bucket=hash('sha256',$scope.'|'.$ip); $time=time();
        $hits=$this->db->transaction(function()use($bucket,$time,$window){
            $this->db->run('DELETE FROM rate_limits WHERE expires_at<=?',[$time]);
            $this->db->run('INSERT INTO rate_limits(bucket,hits,expires_at) VALUES (?,1,?) ON CONFLICT(bucket) DO UPDATE SET hits=hits+1',[$bucket,$time+$window]);
            return $this->db->one('SELECT hits,expires_at FROM rate_limits WHERE bucket=?',[$bucket]);
        });
        if ($hits['hits']>$limit) throw new HttpError(429,'Demasiadas solicitudes. Esperá antes de reintentar.');
    }
    private function conversation(string $id,array $headers): array {
        $id=validUuid($id); $c=$this->db->one('SELECT * FROM conversations WHERE id=?',[$id]);
        preg_match('/^Bearer ([a-f0-9]{64})$/',$headers['authorization']??'',$match);
        ensure($c!==null && isset($match[1]) && hash_equals($c['token_hash'],hash('sha256',$match[1])),404,'Conversación no disponible'); return $c;
    }
    private function messages(string $id): array { return $this->db->all('SELECT role,content,created_at FROM messages WHERE conversation_id=? ORDER BY id',[$id]); }
    private function ticket(string $id): ?array { return $this->db->one('SELECT id,code,status,priority,created_at FROM tickets WHERE conversation_id=?',[$id]); }
    private function message(string $id,string $role,string $content): void { $this->db->run('INSERT INTO messages(conversation_id,role,content,created_at) VALUES (?,?,?,?)',[$id,$role,$content,now()]); }
    private function events(string $id): array { return $this->db->all("SELECT content,created_at FROM ticket_events WHERE ticket_id=? AND kind='status' ORDER BY id",[$id]); }
    private function event(string $id,string $kind,string $content,string $actor): void { $this->db->run('INSERT INTO ticket_events(ticket_id,kind,content,actor,created_at) VALUES (?,?,?,?,?)',[$id,$kind,$content,$actor,now()]); }
    private function cookie(string $value,bool $clear=false): string { return 'iturno_session='.$value.'; Path=/api/admin; Max-Age='.($clear?'0':'28800').'; HttpOnly; SameSite=Strict'.($this->config['APP_ENV']==='production'?'; Secure':''); }
    private function dispatch(string $method,string $path,array $body,array $headers,array $query,array $cookies): Response {
        $db=$this->db; $mode=$this->config['AI_MODE'];
        if ($method==='GET' && $path==='/api/health') { $db->one('SELECT 1'); return new Response(['ok'=>true,'mode'=>$mode]); }
        if ($method==='GET' && $path==='/api/config') return new Response(['mode'=>$mode,'states'=>STATES,'priorities'=>PRIORITIES]);
        if ($method==='POST' && $path==='/api/conversations') {
            fields($body,[]); $id=uuid(); $access=token(); $date=now();
            $db->transaction(function()use($db,$id,$access,$date,$mode){ $db->run('INSERT INTO conversations(id,token_hash,created_at,updated_at,mode) VALUES (?,?,?,?,?)',[$id,hash('sha256',$access),$date,$date,$mode]); $this->message($id,'assistant',GREETING); });
            return new Response(['id'=>$id,'accessToken'=>$access,'mode'=>$mode,'messages'=>$this->messages($id),'status'=>'active','state'=>(object)[]],201);
        }
        if ($method==='GET' && preg_match('#^/api/conversations/([^/]+)$#',$path,$m)) {
            $c=$this->conversation($m[1],$headers); $id=$c['id'];
            return new Response(['id'=>$id,'messages'=>$this->messages($id),'category'=>$c['category'],'status'=>$c['status'],'state'=>json_decode($c['state']),'ticket'=>$this->ticket($id),'mode'=>$c['mode'],'completedRequestIds'=>array_column($db->all('SELECT request_id FROM requests WHERE conversation_id=? AND response IS NOT NULL',[$id]),'request_id'),'pendingRequest'=>$db->one('SELECT request_id,message FROM requests WHERE conversation_id=? AND response IS NULL ORDER BY rowid DESC LIMIT 1',[$id])]);
        }
        if ($method==='POST' && $path==='/api/chat') {
            fields($body,['conversationId','requestId','message'],['conversationId','requestId','message']);
            $id=validUuid($body['conversationId']); $request=validUuid($body['requestId']); $message=text($body['message'],2000);
            $this->conversation($id,$headers);
            return $db->conversationLock($id,function()use($db,$id,$request,$message,$headers,$mode){
                $c=$this->conversation($id,$headers);
                $previous=$db->one('SELECT * FROM requests WHERE conversation_id=? AND request_id=?',[$id,$request]);
                if ($previous) {
                    ensure($previous['message']===$message,409,'El identificador ya fue usado con otro mensaje');
                    if ($previous['response']!==null) return new Response(json_decode($previous['response']));
                }
                ensure(!in_array($c['status'],['resolved','ticketed'],true),409,'El caso ya está registrado. Iniciá una nueva conversación.');
                $pending=$db->one('SELECT request_id FROM requests WHERE conversation_id=? AND response IS NULL',[$id]);
                ensure(!$pending || $pending['request_id']===$request,409,'Reintentá primero el mensaje pendiente');
                $history=$this->messages($id); ensure(count($history)<101 || $previous!==null,422,'La conversación alcanzó el límite. Iniciá una nueva consulta.');
                if (!$previous) $db->transaction(function()use($db,$id,$request,$message){ $db->run('INSERT INTO requests(conversation_id,request_id,message) VALUES (?,?,?)',[$id,$request,$message]); $this->message($id,'user',$message); $db->run('UPDATE conversations SET updated_at=? WHERE id=?',[now(),$id]); });
                $old=json_decode($c['state'],true);
                try { $result=AI::validate(($this->reply)($this->messages($id),$old)); }
                catch (\Throwable $e) { error_log('ITurno AI request failed: '.get_class($e)); throw new HttpError(503,'El asistente no pudo responder. Tu mensaje quedó guardado. Reintentá el envío en unos segundos.'); }
                if ($result['action']==='resolved' && !(($old['action']??'')==='suggested' && !empty($old['solution']) && isConfirmation($message))) {
                    $result['action']=empty($old['solution'])?'continue':'suggested';
                    $result['reply']=empty($old['solution'])?'Necesito comprender mejor el problema antes de registrar una solución. ¿Qué sucede con tu equipo?':'Dejé la solución como sugerida. Para cerrarla, confirmá si el paso anterior solucionó el problema.';
                }
                if ($result['action']==='resolved') $result['solution']=$old['solution'];
                if ($result['action']==='suggested' && $result['solution']==='') $result['action']='continue';
                if (($old['action']??'')==='escalate') $result['action']='escalate';
                $state=array_replace($old,$result); $status=match($result['action']) { 'resolved'=>'resolved','suggested'=>'suggested','escalate'=>'awaiting_details',default=>'active' };
                $output=['reply'=>$result['reply'],'state'=>$state,'status'=>$status,'category'=>$result['category'],'mode'=>$mode];
                $db->transaction(function()use($db,$id,$request,$message,$history,$result,$state,$status,$output){
                    $this->message($id,'assistant',$result['reply']);
                    $db->run('UPDATE conversations SET state=?,status=?,category=?,updated_at=? WHERE id=?',[json($state),$status,$result['category'],now(),$id]);
                    if (in_array($result['action'],['suggested','resolved'],true)) {
                        $users=array_values(array_filter($history,fn($m)=>$m['role']==='user'));
                        $db->run('INSERT INTO resolutions(conversation_id,problem,category,diagnosis,solution,confirmation,confirmed,created_at) VALUES (?,?,?,?,?,?,?,?) ON CONFLICT(conversation_id) DO UPDATE SET diagnosis=excluded.diagnosis, solution=excluded.solution, confirmation=excluded.confirmation, confirmed=excluded.confirmed',[$id,$users[0]['content']??$message,$result['category'],$result['diagnosis'],$result['solution'],$result['action']==='resolved'?$message:null,$result['action']==='resolved'?1:0,now()]);
                    }
                    $db->run('UPDATE requests SET response=? WHERE conversation_id=? AND request_id=?',[json($output),$id,$request]);
                }); return new Response($output);
            });
        }
        if ($method==='POST' && $path==='/api/tickets') {
            fields($body,['conversationId','name','contact','equipment','description','consent'],['conversationId','name','contact','equipment','description','consent']);
            $id=validUuid($body['conversationId']); $name=text($body['name'],100,2); $contact=contact($body['contact']); $equipment=text($body['equipment'],150,2); $description=text($body['description'],2000,5); ensure($body['consent']===true,400,'Se requiere consentimiento de contacto'); $this->conversation($id,$headers);
            return $db->conversationLock($id,function()use($db,$id,$name,$contact,$equipment,$description,$headers){
                $c=$this->conversation($id,$headers); $existing=$this->ticket($id); if ($existing) return new Response(['ticket'=>$existing]);
                ensure($c['status']==='awaiting_details',409,'El asistente todavía no derivó este caso');
                $s=json_decode($c['state'],true); $ticket=uuid(); $client=uuid(); $code='IT-'.strtoupper(bin2hex(random_bytes(10))); $date=now();
                $db->transaction(function()use($db,$id,$name,$contact,$equipment,$description,$c,$s,$ticket,$client,$code,$date){
                    $db->run('INSERT INTO clients(id,name,contact,created_at) VALUES (?,?,?,?)',[$client,$name,$contact,$date]);
                    $db->run('INSERT INTO tickets(id,code,conversation_id,client_id,equipment,category,description,diagnosis,summary,priority,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[$ticket,$code,$id,$client,$equipment,$c['category'],$description,$s['diagnosis']?:'Pendiente de evaluación técnica',$s['summary']?:$description,in_array($s['priority']??'',PRIORITIES,true)?$s['priority']:'Media',$date,$date]);
                    $this->event($ticket,'status','Nuevo · solicitud registrada con consentimiento de contacto','Sistema');
                    $db->run("UPDATE conversations SET status='ticketed',updated_at=? WHERE id=?",[$date,$id]);
                    $this->message($id,'assistant',"Tu solicitud quedó registrada con el código $code. Guardalo para consultar el estado junto con tu contacto. Un técnico coordinará los próximos pasos; todavía no hay un turno confirmado.");
                }); return new Response(['ticket'=>$this->ticket($id),'messages'=>$this->messages($id)],201);
            });
        }
        if ($method==='GET' && preg_match('#^/api/tickets/([^/]+)$#',$path,$m)) {
            $id=validUuid($m[1]); $t=$db->one('SELECT * FROM tickets WHERE id=?',[$id]); ensure($t!==null,404,'Ticket no disponible'); $this->conversation($t['conversation_id'],$headers);
            return new Response(array_intersect_key($t,array_flip(['id','code','status','category','equipment','created_at','updated_at']))+['history'=>$this->events($id)]);
        }
        if ($method==='POST' && $path==='/api/tracking') {
            fields($body,['code','contact'],['code','contact']); $code=strtoupper(text($body['code'],30)); $contact=contact($body['contact']);
            $t=$db->one('SELECT t.id,t.code,t.status,t.category,t.created_at,t.updated_at FROM tickets t JOIN clients c ON t.client_id=c.id WHERE t.code=? AND c.contact=?',[$code,$contact]);
            ensure($t!==null,404,'No encontramos una solicitud con esos datos'); return new Response($t+['history'=>$this->events($t['id'])]);
        }
        if ($method==='POST' && $path==='/api/admin/login') {
            fields($body,['username','password'],['username','password']); $username=text($body['username'],100); ensure(is_string($body['password']) && strlen($body['password'])>=1 && strlen($body['password'])<=200,400,'Contraseña inválida');
            $stored=$this->config['ADMIN_PASSWORD_HASH']; ensure($stored!=='' && !str_starts_with($stored,'scrypt:'),503,'Configurá el administrador con php scripts/admin.php.');
            $valid=password_verify($body['password'],$stored); ensure($valid && hash_equals($this->config['ADMIN_USER'],$username),401,'Usuario o contraseña incorrectos');
            $access=token(); $db->run('DELETE FROM sessions WHERE expires_at<=?',[(int)(microtime(true)*1000)]);
            $db->run('INSERT INTO sessions(token_hash,username,expires_at) VALUES (?,?,?)',[hash('sha256',$access),$username,(int)(microtime(true)*1000)+28800000]);
            return new Response(['username'=>$username],200,['Set-Cookie'=>$this->cookie($access)]);
        }
        if (str_starts_with($path,'/api/admin/')) return $this->adminRoutes($method,$path,$body,$query,$cookies);
        throw new HttpError(404,'Ruta no encontrada');
    }
    private function adminRoutes(string $method,string $path,array $body,array $query,array $cookies): Response {
        $db=$this->db; $key=$cookies['iturno_session']??'';
        $session=is_string($key) && preg_match('/^[a-f0-9]{64}$/',$key) ? $db->one('SELECT * FROM sessions WHERE token_hash=? AND expires_at>?',[hash('sha256',$key),(int)(microtime(true)*1000)]) : null;
        ensure($session!==null,401,'Iniciá sesión para continuar'); $actor=$session['username'];
        if ($method==='GET' && $path==='/api/admin/me') return new Response(['username'=>$actor]);
        if ($method==='POST' && $path==='/api/admin/logout') { $db->run('DELETE FROM sessions WHERE token_hash=?',[hash('sha256',$key)]); return new Response(['ok'=>true],200,['Set-Cookie'=>$this->cookie('',true)]); }
        if ($method==='GET' && $path==='/api/admin/stats') {
            $count=fn($sql)=>(int)$db->one($sql)['n'];
            $received=$count("SELECT COUNT(*) n FROM conversations WHERE EXISTS (SELECT 1 FROM messages WHERE conversation_id=conversations.id AND role='user')"); $resolved=$count('SELECT COUNT(*) n FROM resolutions WHERE confirmed=1');
            return new Response(['received'=>$received,'resolvedAI'=>$resolved,'tickets'=>$count('SELECT COUNT(*) n FROM tickets'),'pending'=>$count("SELECT COUNT(*) n FROM tickets WHERE status NOT IN ('Resuelto','Entregado','Cancelado')"),'resolvedTickets'=>$count("SELECT COUNT(*) n FROM tickets WHERE status IN ('Resuelto','Entregado')"),'automaticRate'=>$received?(int)round($resolved/$received*100):0,'frequent'=>$db->all("SELECT category,COUNT(*) count FROM conversations WHERE EXISTS (SELECT 1 FROM messages WHERE conversation_id=conversations.id AND role='user') GROUP BY category ORDER BY count DESC LIMIT 5")]);
        }
        if ($method==='GET' && $path==='/api/admin/tickets') {
            fields($query,['status','priority','q']); $where=[]; $params=[];
            foreach (['status'=>STATES,'priority'=>PRIORITIES] as $key=>$choices) if (isset($query[$key])) { $where[]="t.$key=?"; $params[]=choice($query[$key],$choices); }
            if (isset($query['q'])) { $q=text($query['q'],100,0); $like='%'.strtr($q,['\\'=>'\\\\','%'=>'\\%','_'=>'\\_']).'%'; $where[]="(c.name LIKE ? ESCAPE '\' OR t.code LIKE ? ESCAPE '\')"; array_push($params,$like,$like); }
            return new Response($db->all('SELECT t.*,c.name,c.contact FROM tickets t JOIN clients c ON t.client_id=c.id '.($where?'WHERE '.implode(' AND ',$where):'').' ORDER BY t.created_at DESC LIMIT 200',$params));
        }
        if (preg_match('#^/api/admin/tickets/([^/]+)$#',$path,$m) && in_array($method,['GET','PATCH'],true)) {
            $id=validUuid($m[1]); $t=$db->one('SELECT t.*,c.name,c.contact FROM tickets t JOIN clients c ON t.client_id=c.id WHERE t.id=?',[$id]); ensure($t!==null,404,'Ticket no encontrado');
            if ($method==='GET') return new Response($t+['messages'=>$this->messages($t['conversation_id']),'events'=>$db->all('SELECT * FROM ticket_events WHERE ticket_id=? ORDER BY id',[$id])]);
            fields($body,['status','priority','note']); ensure(count($body)>0,400,'No hay cambios para guardar');
            if (array_key_exists('status',$body)) choice($body['status'],STATES); if (array_key_exists('priority',$body)) choice($body['priority'],PRIORITIES); if (array_key_exists('note',$body)) $body['note']=text($body['note'],3000);
            $db->transaction(function()use($db,$id,$body,$actor){
                // Reread inside the write transaction so the history remains correct under simultaneous updates.
                $t=$db->one('SELECT * FROM tickets WHERE id=?',[$id]);
                $db->run('UPDATE tickets SET status=?,priority=?,updated_at=? WHERE id=?',[$body['status']??$t['status'],$body['priority']??$t['priority'],now(),$id]);
                foreach (['status','priority'] as $k) if (isset($body[$k]) && $body[$k]!==$t[$k]) $this->event($id,$k,$t[$k].' → '.$body[$k],$actor);
                if (isset($body['note'])) $this->event($id,'note',$body['note'],$actor);
            }); return new Response(['ok'=>true]);
        }
        if ($method==='GET' && $path==='/api/admin/conversations') {
            fields($query,['kind']); $kind=choice($query['kind']??'all',['all','resolved','suggested','human']);
            $where=match($kind) { 'resolved'=>'WHERE r.confirmed=1','suggested'=>"WHERE r.confirmed=0 AND c.status!='ticketed'",'human'=>"WHERE c.status IN ('ticketed','awaiting_details')",default=>'' };
            return new Response($db->all("SELECT c.id,c.created_at,c.category,c.status,c.mode,r.problem,r.diagnosis,r.solution,r.confirmed,r.confirmation,(SELECT content FROM messages WHERE conversation_id=c.id AND role='user' ORDER BY id LIMIT 1) first_message FROM conversations c LEFT JOIN resolutions r ON r.conversation_id=c.id $where ORDER BY c.created_at DESC LIMIT 200"));
        }
        if ($method==='GET' && preg_match('#^/api/admin/conversations/([^/]+)$#',$path,$m)) {
            $id=validUuid($m[1]); $c=$db->one('SELECT id,category,status,state,mode,created_at FROM conversations WHERE id=?',[$id]); ensure($c!==null,404,'Conversación no encontrada'); $c['state']=json_decode($c['state']); return new Response($c+['messages'=>$this->messages($id)]);
        }
        throw new HttpError(404,'Ruta no encontrada');
    }
}
