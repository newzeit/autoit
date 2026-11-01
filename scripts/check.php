<?php
declare(strict_types=1);
if (PHP_VERSION_ID<80200) { fwrite(STDERR,"Se requiere PHP 8.2 o superior.\n"); exit(1); }
foreach (['pdo_sqlite','sqlite3','curl','mbstring'] as $extension) if (!extension_loaded($extension)) { fwrite(STDERR,"Falta la extensión PHP: $extension\n"); exit(1); }
echo 'PHP '.PHP_VERSION.' · SQLite, cURL y mbstring disponibles'.PHP_EOL;
