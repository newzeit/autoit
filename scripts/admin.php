<?php
declare(strict_types=1);
require __DIR__.'/../src/php/bootstrap.php';
function hiddenPassword(string $question): string {
    if (PHP_OS_FAMILY==='Windows') throw new RuntimeException('El alta interactiva requiere una terminal Linux/macOS.');
    $state=trim((string)shell_exec('stty -g 2>/dev/null'));
    if ($state==='') throw new RuntimeException('No se pudo ocultar la entrada de la terminal.');
    echo $question; system('stty -echo');
    try { return rtrim((string)fgets(STDIN),"\r\n"); }
    finally { system('stty '.escapeshellarg($state)); echo PHP_EOL; }
}
if (!stream_isatty(STDIN)) { fwrite(STDERR,"Ejecutá este comando en una terminal interactiva.\n"); exit(1); }
try {
    echo 'Usuario administrativo [admin]: '; $username=trim((string)fgets(STDIN)) ?: 'admin';
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/',$username)) throw new RuntimeException('Usá de 3 a 50 letras, números, puntos o guiones.');
    $password=hiddenPassword('Contraseña (12 a 72 bytes, no se muestra): ');
    if (strlen($password)<12 || strlen($password)>72) throw new RuntimeException('La contraseña debe tener entre 12 y 72 bytes.');
    if (!hash_equals($password,hiddenPassword('Repetí la contraseña: '))) throw new RuntimeException('Las contraseñas no coinciden.');
    $file=ITurno\ROOT.'/.env';
    $contents=file_get_contents(is_file($file)?$file:ITurno\ROOT.'/.env.example');
    foreach (['ADMIN_USER'=>$username,'ADMIN_PASSWORD_HASH'=>ITurno\passwordHash($password)] as $key=>$value) {
        $line=$key.'='.$value;
        // Callback prevents $ in password hashes from becoming regex replacement references.
        $contents=preg_match('/^'.$key.'=.*$/m',$contents)?preg_replace_callback('/^'.$key.'=.*$/m',fn()=>$line,$contents):rtrim($contents)."\n$line\n";
    }
    $cfg=ITurno\config(); $db=new ITurno\Database($cfg['DB_PATH']);
    // Write atomically, preserving unrelated configuration values.
    $temporary=tempnam(ITurno\ROOT,'.env-'); chmod($temporary,0600);
    try { if (file_put_contents($temporary,$contents)===false || !rename($temporary,$file)) throw new RuntimeException('No se pudo guardar .env'); }
    finally { if (is_file($temporary)) unlink($temporary); }
    $db->run('DELETE FROM sessions');
    echo "Administrador configurado. Las sesiones anteriores fueron invalidadas.\n";
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1); }
