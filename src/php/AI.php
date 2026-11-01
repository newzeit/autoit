<?php
declare(strict_types=1);
namespace ITurno;
final class AI {
    public function __construct(private array $config, private ?\Closure $transport=null) {
        ensure(in_array($config['AI_MODE'],['demo','openai'],true),503,'AI_MODE debe ser demo u openai');
        ensure($config['AI_MODE']!=='openai' || $config['OPENAI_API_KEY']!=='',503,'AI_MODE=openai requiere OPENAI_API_KEY. Usá AI_MODE=demo para demostrar sin clave.');
    }
    public static function validate(array $result): array {
        foreach (['reply'=>4000,'diagnosis'=>3000,'solution'=>3000,'summary'=>4000] as $key=>$max) $result[$key]=text($result[$key]??null,$max,$key==='reply'?1:0);
        $result['category']=choice($result['category']??null,CATEGORIES);
        $result['priority']=choice($result['priority']??null,PRIORITIES);
        $result['action']=choice($result['action']??null,['continue','suggested','resolved','escalate']);
        ensure(isset($result['suggestions']) && is_array($result['suggestions']) && array_is_list($result['suggestions']) && count($result['suggestions'])<=4,400,'Sugerencias inválidas');
        foreach ($result['suggestions'] as &$s) $s=text($s,100);
        return $result;
    }
    public function reply(array $messages, array $state): array {
        if ($this->config['AI_MODE']==='demo') return self::validate(demoReply($messages,$state));
        $properties=[];
        foreach (['reply','diagnosis','solution','summary'] as $key) $properties[$key]=['type'=>'string'];
        $properties['category']=['type'=>'string','enum'=>CATEGORIES];
        $properties['priority']=['type'=>'string','enum'=>PRIORITIES];
        $properties['action']=['type'=>'string','enum'=>['continue','suggested','resolved','escalate']];
        $properties['suggestions']=['type'=>'array','items'=>['type'=>'string']];
        $body=['model'=>$this->config['OPENAI_MODEL'],'store'=>false,'instructions'=>file_get_contents(__DIR__.'/support-prompt.txt'),'max_output_tokens'=>1600,'input'=>array_map(fn($m)=>['role'=>$m['role'],'content'=>$m['content']],$messages),'text'=>['format'=>['type'=>'json_schema','name'=>'iturno_support','strict'=>true,'schema'=>['type'=>'object','properties'=>$properties,'required'=>array_keys($properties),'additionalProperties'=>false]]]];
        if ($this->transport) $response=($this->transport)($body);
        else {
            $curl=curl_init('https://api.openai.com/v1/responses');
            curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$this->config['OPENAI_API_KEY'],'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json($body),CURLOPT_CONNECTTIMEOUT_MS=>5000,CURLOPT_TIMEOUT_MS=>max(1000,min(60000,(int)$this->config['AI_TIMEOUT_MS']))]);
            $raw=curl_exec($curl); $code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            if ($raw===false || $code<200 || $code>=300) throw new \RuntimeException('AI_PROVIDER_ERROR');
            $response=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        }
        if (($response['status']??'')!=='completed') throw new \RuntimeException('AI_INCOMPLETE');
        $output=''; foreach ($response['output']??[] as $item) foreach ($item['content']??[] as $part) if (($part['type']??'')==='output_text') $output.=$part['text'];
        return self::validate(json_decode($output,true,512,JSON_THROW_ON_ERROR));
    }
}
