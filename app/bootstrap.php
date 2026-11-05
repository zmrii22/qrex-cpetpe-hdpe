<?php
declare(strict_types=1);
const ROOT = __DIR__ . '/..';
require __DIR__ . '/RequestTiming.php';
require __DIR__ . '/OrderSourceCache.php';
RequestTiming::start();
$settings = require ROOT . '/config/settings.php';
date_default_timezone_set($settings['timezone']);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
$storageDir = ROOT . '/storage';
if (!is_dir($storageDir)) @mkdir($storageDir, 0775, true);
ini_set('error_log', is_dir($storageDir) && is_writable($storageDir) ? $storageDir . '/application.log' : sys_get_temp_dir() . '/qrex-application.log');
require __DIR__ . '/TursoConnection.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Sampling.php';
require __DIR__ . '/Rules.php';
require __DIR__ . '/ManpowerSync.php';
require __DIR__ . '/Inspections.php';
require __DIR__ . '/Reports.php';
function db(): PDO|TursoConnection { static $db; global $settings; return $db ??= Database::connect($settings); }
function db_driver(): string { return db()->getAttribute(PDO::ATTR_DRIVER_NAME); }
function last_insert_id(): int {
    if (db_driver()==='sqlsrv') return (int)db()->query('SELECT CAST(SCOPE_IDENTITY() AS INT)')->fetchColumn();
    return (int)db()->lastInsertId();
}
function master(): array { static $data; return $data ??= json_decode(file_get_contents(ROOT . '/resources/master.json'), true, 512, JSON_THROW_ON_ERROR); }
function json_out(array $data, int $status=200) {
    $body=json_encode($data,JSON_THROW_ON_ERROR);
    if(PHP_SAPI!=='cli' && function_exists('ob_gzhandler') && !ini_get('zlib.output_compression') && !in_array('ob_gzhandler',ob_list_handlers(),true)) ob_start('ob_gzhandler');
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    RequestTiming::emit($status); echo $body; exit;
}
function query(string $sql, array $params=[]): PDOStatement|TursoStatement {
    $connection=db();
    $work=function() use ($connection,$sql,$params) { $stmt=$connection->prepare($sql); $stmt->execute($params); return $stmt; };
    return $connection instanceof TursoConnection ? $work() : RequestTiming::measure('db',$work,1);
}
function query_all_batch(array $statements): array {
    if (db() instanceof TursoConnection) {
        return array_map([TursoStatement::class, 'decodeRows'], db()->executeBatch($statements));
    }
    return array_map(fn($statement)=>query($statement['sql'],$statement['params'] ?? [])->fetchAll(),$statements);
}
function json_value_sql(string $column, string $key): string {
    demand((bool)preg_match('/^[a-z_]+$/', $key), 'Invalid JSON key.');
    return match (db_driver()) {
        'mysql' => "JSON_UNQUOTE(JSON_EXTRACT($column,'$.$key'))",
        'sqlsrv' => "JSON_VALUE($column,'\$.$key')",
        default => "json_extract($column,'\$.$key')",
    };
}
function date_day_sql(string $column): string {
    return match (db_driver()) {
        'sqlsrv' => "CONVERT(varchar(10),$column,23)",
        'mysql' => "SUBSTRING($column,1,10)",
        default => "SUBSTR($column,1,10)",
    };
}
function audit(string $action, string $entity, string $id, array $details=[]): void { if($entity==='inspection') Inspections::auditItem($action,$id,$details); }
function demand(bool $ok, string $message, int $code=422): void { if (!$ok) throw new DomainException($message,$code); }
function actor(): array {
    $id=(int)($_SESSION['uid']??0);
    $cached=$GLOBALS['qrexRequestActor']??null;
    if($cached && (int)$cached['id']===$id) return $cached;
    $user=query('SELECT id,full_name AS name,email,employee_id,role,active FROM M_user WHERE id=?',[$id])->fetch();
    demand((bool)$user && (bool)$user['active'],'Please sign in to continue.',401);
    return $user;
}
function role(array $roles): array { $user=actor(); demand(in_array($user['role'],$roles,true),'You do not have permission for this action.',403); return $user; }
function safe_text(mixed $value, int $limit=200): string { demand(is_string($value)||is_numeric($value),'Enter a valid text value.'); $value=trim((string)$value); $length=function_exists('mb_strlen') ? mb_strlen($value) : strlen($value); demand($length<=$limit,'A value is too long.'); return $value; }
