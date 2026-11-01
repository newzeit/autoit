<?php
declare(strict_types=1);
// Development server only. Apache serves public/ directly in production.
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?? '/');
if (str_starts_with($path,'/api/') || $path==='/api') { require __DIR__.'/public/index.php'; return; }
$public=realpath(__DIR__.'/public'); $file=realpath($public.($path==='/'?'/index.html':$path));
$types=['html'=>'text/html; charset=utf-8','css'=>'text/css; charset=utf-8','js'=>'text/javascript; charset=utf-8','png'=>'image/png','svg'=>'image/svg+xml','ico'=>'image/x-icon'];
$extension=$file ? pathinfo($file,PATHINFO_EXTENSION) : '';
if (!$file || !str_starts_with($file,$public.DIRECTORY_SEPARATOR) || preg_match('#(?:^|/)\.#',$path) || !is_file($file) || !isset($types[$extension])) { http_response_code(404); echo 'No encontrado'; return; }
header_remove('X-Powered-By');
header('Content-Type: '.$types[$extension]);
header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY'); header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
readfile($file);
