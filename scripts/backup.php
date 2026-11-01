<?php
declare(strict_types=1);
require_once __DIR__.'/../src/php/bootstrap.php';
function backupDatabase(array $cfg): string {
    $source=ITurno\absolutePath($cfg['DB_PATH']);
    if (!is_file($source)) throw new RuntimeException('La base no existe. Iniciá la aplicación primero.');
    $directory=ITurno\absolutePath($cfg['BACKUP_DIR']);
    if (!is_dir($directory) && !mkdir($directory,0700,true)) throw new RuntimeException('No se pudo crear el directorio de backups');
    $path=$directory.'/iturno-'.gmdate('Y-m-d_H-i-s').'-'.bin2hex(random_bytes(4)).'.sqlite';
    $db=new SQLite3($source,SQLITE3_OPEN_READONLY); $db->enableExceptions(true); $db->busyTimeout(5000);
    $destination=new SQLite3($path); $destination->enableExceptions(true); $destination->busyTimeout(5000);
    try {
        if (!$db->backup($destination) || $destination->querySingle('PRAGMA integrity_check')!=='ok') throw new RuntimeException('No se pudo verificar el backup');
        chmod($path,0600); return $path;
    } finally { $destination->close(); $db->close(); }
}
if (realpath($_SERVER['SCRIPT_FILENAME'])===__FILE__) {
    try { echo 'Backup consistente creado: '.backupDatabase(ITurno\config()).PHP_EOL; }
    catch (Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1); }
}
