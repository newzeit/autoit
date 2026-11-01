<?php
declare(strict_types=1);
namespace ITurno;
final class Database {
    public \PDO $pdo;
    public function __construct(public string $path) {
        if ($path !== ':memory:') {
            $this->path = absolutePath($path);
            if (!is_dir(dirname($this->path)) && !mkdir(dirname($this->path),0700,true)) throw new \RuntimeException('No se pudo crear el directorio de datos');
        }
        $this->pdo = new \PDO('sqlite:'.$this->path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
        $this->pdo->exec('PRAGMA busy_timeout=5000');
        $this->pdo->exec(file_get_contents(__DIR__.'/schema.sql'));
    }
    public function run(string $sql, array $params=[]): \PDOStatement { $stmt=$this->pdo->prepare($sql); $stmt->execute($params); return $stmt; }
    public function one(string $sql, array $params=[]): ?array { return $this->run($sql,$params)->fetch() ?: null; }
    public function all(string $sql, array $params=[]): array { return $this->run($sql,$params)->fetchAll(); }
    public function transaction(callable $work): mixed {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try { $result=$work(); $this->pdo->exec('COMMIT'); return $result; }
        catch (\Throwable $e) { $this->pdo->exec('ROLLBACK'); throw $e; }
    }
    public function conversationLock(string $id, callable $work): mixed {
        // Shared by PHP-FPM workers on the same host. No database transaction held during AI calls.
        $directory=$this->path === ':memory:' ? sys_get_temp_dir().'/iturno-test-locks' : dirname($this->path).'/locks';
        if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new \RuntimeException('No se pudo crear el directorio de bloqueos');
        $handle=fopen($directory.'/'.hash('sha256',$id).'.lock','c');
        if (!$handle) throw new \RuntimeException('No se pudo abrir el bloqueo');
        if (!flock($handle,LOCK_EX|LOCK_NB)) { fclose($handle); throw new HttpError(409,'Hay una respuesta en proceso. Reintentá en unos segundos.'); }
        try { return $work(); } finally { flock($handle,LOCK_UN); fclose($handle); }
    }
}
