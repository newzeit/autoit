<?php
declare(strict_types=1);
require __DIR__.'/check.php';
require __DIR__.'/../src/php/bootstrap.php';
$cfg=ITurno\config();
try { new ITurno\AI($cfg); } catch (Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1); }
$host=$cfg['HOST']; $port=filter_var($cfg['PORT'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
if (!$port || !filter_var($host,FILTER_VALIDATE_IP)) { fwrite(STDERR,"HOST debe ser una IP y PORT un puerto válido.\n"); exit(1); }
echo "ITurno AI · http://$host:$port · {$cfg['AI_MODE']}\n";
$process=proc_open([PHP_BINARY,'-S',"$host:$port",'-t',realpath(__DIR__.'/../public'),realpath(__DIR__.'/../router.php')],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);
exit(is_resource($process)?proc_close($process):1);
