<?php
declare(strict_types=1);
require __DIR__.'/check.php';
require __DIR__.'/backup.php';
try {
    echo 'Backup: '.backupDatabase(ITurno\config()).PHP_EOL;
    $process=proc_open([PHP_BINARY,ITurno\ROOT.'/test/run.php'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);
    if (!is_resource($process) || proc_close($process)!==0) throw new RuntimeException('Fallaron las pruebas');
    echo "Actualización verificada. Recargá PHP-FPM si usás OPcache y comprobá /api/health.\n";
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1); }
