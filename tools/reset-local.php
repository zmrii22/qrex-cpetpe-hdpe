<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') {http_response_code(404);exit;}

$root=dirname(__DIR__);
$settings=require $root.'/config/settings.php';
$dbPath=$root.'/storage/local-test.sqlite';

if(str_starts_with((string)$settings['dsn'],'sqlsrv:')) {
    fwrite(STDERR,"Refusing to reset SQL Server. This tool is only for the local SQLite demo database.\n");
    exit(1);
}

foreach([$dbPath,$dbPath.'-wal',$dbPath.'-shm'] as $file) {
    if(!is_file($file)) continue;
    $backup=$file.'.bak-'.date('Ymd-His');
    if(!copy($file,$backup)) {
        fwrite(STDERR,"Could not back up $file\n");
        exit(1);
    }
    unlink($file);
}

require $root.'/app/TursoConnection.php';
require $root.'/app/Database.php';
require $root.'/app/Rules.php';
require $root.'/app/Inspections.php';

function local_db(): PDO {
    static $db;
    global $dbPath;
    if($db) return $db;
    $db=new PDO('sqlite:'.$dbPath,'','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('PRAGMA journal_mode=WAL');
    return $db;
}
function db(): PDO { return local_db(); }
function query(string $sql,array $params=[]): PDOStatement { $stmt=db()->prepare($sql);$stmt->execute($params);return $stmt; }
function demand(bool $ok,string $message,int $code=422): void { if(!$ok) throw new RuntimeException($message,$code); }
function master(): array { return json_decode(file_get_contents(dirname(__DIR__).'/resources/master.json'),true,512,JSON_THROW_ON_ERROR); }

try {
    Database::install(db());
    Database::seed(db(),master());
    Database::seedDemoOrders(db());
    $password=getenv('QUALITY_DEMO_PASSWORD') ?: 'QualisDemo!2026';
    demand(strlen($password)>=6,'Demo password must have at least 6 characters.');
    $users=[
        ['Quality Administrator','admin@quality.local','admin'],
        ['Demo Inspector','inspector@quality.local','inspector'],
        ['Demo Supervisor','supervisor@quality.local','supervisor'],
        ['Demo Viewer','viewer@quality.local','viewer'],
    ];
    foreach($users as [$name,$email,$role]) {
        query('INSERT INTO M_user (username,email,password_hash,full_name,role,active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)',[
            $email,$email,password_hash($password,PASSWORD_DEFAULT),$name,$role,1,date('Y-m-d H:i:s'),date('Y-m-d H:i:s')
        ]);
    }
    file_put_contents($root.'/storage/local-access.txt',
        "QREX local demo installation\n".
        "URL: http://localhost/CPETPE_dev/quality/public/\n".
        "Password for all demo accounts: $password\n\n".
        "Admin: admin@quality.local\n".
        "Inspector: inspector@quality.local\n".
        "Supervisor: supervisor@quality.local\n".
        "Viewer: viewer@quality.local\n\n".
        "Isolated test SQLite file: quality/storage/local-test.sqlite\n".
        "Sales Order lookup uses QAPQC02.QREX_MMSO when reachable; DEMO-* orders are provided as development fallback.\n"
    );
    echo "Local SQLite demo database reset.\n";
    echo "Specification parameters: ".query('SELECT COUNT(*) FROM M_masterlist_specifications')->fetchColumn()."\n";
    echo "Sales Order snapshots: ".query('SELECT COUNT(*) FROM T_sales_order')->fetchColumn()."\n";
    echo "CPETPE items: ".query('SELECT COUNT(*) FROM T_item_CPETPE')->fetchColumn()."\n";
    echo "HDPE items: ".query('SELECT COUNT(*) FROM T_item_HDPE')->fetchColumn()."\n";
    echo "Users: ".query('SELECT COUNT(*) FROM M_user')->fetchColumn()."\n";
    echo "Access details: quality/storage/local-access.txt\n";
} catch(Throwable $e) {
    fwrite(STDERR,"Local reset failed: ".$e->getMessage()."\n");
    exit(1);
}
