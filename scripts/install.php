<?php
declare(strict_types=1);
require __DIR__.'/check.php';
require __DIR__.'/../src/php/bootstrap.php';
umask(0077);
$file=ITurno\ROOT.'/.env';
if (!is_file($file)) { copy(ITurno\ROOT.'/.env.example',$file); chmod($file,0600); }
$db=new ITurno\Database(ITurno\config()['DB_PATH']);
echo "Instalación lista. Ejecutá php scripts/admin.php y php scripts/start.php.\n";
