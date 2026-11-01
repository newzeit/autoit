<?php
declare(strict_types=1);
namespace ITurno;
const ROOT = __DIR__ . '/../..';
const CATEGORIES = ['Consulta general', 'Problema de software', 'Problema de hardware', 'Redes y conectividad', 'Virus y seguridad', 'Instalación y configuración', 'Solicitud de presupuesto', 'Solicitud de turno', 'Seguimiento de reparación', 'Otros'];
const STATES = ['Nuevo', 'Pendiente', 'En diagnóstico', 'En reparación', 'Esperando repuesto', 'Resuelto', 'Entregado', 'Cancelado'];
const PRIORITIES = ['Baja', 'Media', 'Alta'];
const GREETING = '¡Hola! Soy el asistente de ITurno AI. Contame qué le pasa a tu equipo y lo revisamos juntos. No compartas contraseñas ni datos sensibles.';
function config(?string $file = null): array {
    $values = [];
    $file ??= ROOT . '/.env';
    if (is_file($file)) foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        if (!preg_match('/^\s*([A-Z_][A-Z0-9_]*)\s*=(.*)$/', $line, $m)) continue;
        $value = trim($m[2]);
        if (strlen($value) >= 2 && in_array($value[0], ['"', "'"], true) && $value[-1] === $value[0]) $value = substr($value, 1, -1);
        $values[$m[1]] = $value; // Literal values: never expand $ in password hashes.
    }
    $defaults = ['APP_ENV'=>'development', 'HOST'=>'127.0.0.1', 'PORT'=>'3000', 'AI_MODE'=>'demo', 'OPENAI_API_KEY'=>'', 'OPENAI_MODEL'=>'gpt-4o-mini', 'AI_TIMEOUT_MS'=>'25000', 'DB_PATH'=>'./data/iturno.sqlite', 'ADMIN_USER'=>'admin', 'ADMIN_PASSWORD_HASH'=>'', 'BACKUP_DIR'=>'./backups'];
    foreach ($defaults as $key => $default) $values[$key] = getenv($key) !== false ? getenv($key) : ($values[$key] ?? $default);
    return $values;
}
function absolutePath(string $path): string { return str_starts_with($path, '/') ? $path : ROOT . '/' . $path; }
function now(): string { return gmdate('Y-m-d\TH:i:s') . sprintf('.%03dZ', (int) ((microtime(true) * 1000) % 1000)); }
function token(): string { return bin2hex(random_bytes(32)); }
function uuid(): string { $b = random_bytes(16); $b[6]=chr((ord($b[6]) & 0x0f)|0x40); $b[8]=chr((ord($b[8]) & 0x3f)|0x80); $h=bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); }
function json(mixed $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
class HttpError extends \RuntimeException { public function __construct(public int $status, string $message) { parent::__construct($message); } }
function ensure(bool $condition, int $status, string $message): void { if (!$condition) throw new HttpError($status, $message); }
function fields(array $data, array $allowed, array $required = []): void {
    ensure(!array_diff(array_keys($data), $allowed),400,'La solicitud contiene campos no permitidos');
    foreach ($required as $key) ensure(array_key_exists($key,$data),400,"Falta el campo $key");
}
function text(mixed $value, int $max, int $min = 1): string { ensure(is_string($value),400,'Se esperaba texto'); $value=trim($value); ensure(mb_strlen($value)>=$min && mb_strlen($value)<=$max,400,"El texto debe tener entre $min y $max caracteres"); return $value; }
function validUuid(mixed $id): string { ensure(is_string($id) && (bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i',$id),400,'Identificador inválido'); return strtolower($id); }
function choice(mixed $value, array $choices): string { ensure(in_array($value,$choices,true),400,'Valor no permitido'); return $value; }
function contact(mixed $value): string {
    $value=text($value,150,5);
    ensure((bool)filter_var($value,FILTER_VALIDATE_EMAIL) || ((bool)preg_match('/^[+\d ()-]{7,30}$/',$value) && strlen(preg_replace('/\D/','',$value))>=7),400,'Ingresá un email o teléfono válido');
    return str_contains($value,'@') ? mb_strtolower($value) : preg_replace('/\D/','',$value);
}
function passwordHash(string $password): string { return password_hash($password, in_array('argon2id',password_algos(),true) ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT); }
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/DemoAI.php';
require_once __DIR__ . '/AI.php';
require_once __DIR__ . '/Application.php';
