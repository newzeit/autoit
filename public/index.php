<?php
declare(strict_types=1);
use ITurno\{Application,Database,HttpError,Response};
use function ITurno\{config,json};
ini_set('display_errors','0');
require_once __DIR__.'/../src/php/bootstrap.php';
header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
try {
    $cfg=config(); $method=$_SERVER['REQUEST_METHOD']; $body=[];
    if (in_array($method,['POST','PATCH','DELETE'],true)) {
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>16384) throw new HttpError(413,'El mensaje es demasiado grande');
        $raw=file_get_contents('php://input',false,null,0,16385);
        if (strlen($raw)>16384) throw new HttpError(413,'El mensaje es demasiado grande');
        if (strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json') throw new HttpError(415,'Se requiere application/json');
        try { $value=json_decode($raw,false,512,JSON_THROW_ON_ERROR); } catch (JsonException $e) { throw new HttpError(400,'JSON inválido'); }
        if (!$value instanceof stdClass) throw new HttpError(400,'Se requiere un objeto JSON');
        $body=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    }
    $headers=function_exists('getallheaders') ? getallheaders() : [];
    // Apache/FastCGI may expose Authorization via a server variable.
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) $headers['Authorization']=$_SERVER['HTTP_AUTHORIZATION'];
    $app=new Application(new Database($cfg['DB_PATH']),$cfg);
    $response=$app->handle($method,parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),$body,$headers,$_GET,$_COOKIE,$_SERVER['REMOTE_ADDR']??'unknown',!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off');
} catch (HttpError $e) { $response=new Response(['error'=>$e->getMessage()],$e->status); }
catch (Throwable $e) { error_log('ITurno initialization failed: '.get_class($e)); $response=new Response(['error'=>'No se pudo iniciar la aplicación. Revisá la configuración y los logs del servidor.'],503); }
http_response_code($response->status);
foreach ($response->headers as $name=>$value) header($name.': '.$value);
echo json($response->data);
