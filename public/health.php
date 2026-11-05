<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$root = dirname(__DIR__);
$storage = $root . '/storage';
$sessionDir = $storage . '/sessions';
$tokenFile = $storage . '/turso-db-token.txt';

function qrex_health_bool($value) {
    return $value ? 'yes' : 'no';
}

function qrex_health_writable($path) {
    if (!is_dir($path)) @mkdir($path, 0775, true);
    return is_dir($path) && is_writable($path);
}

$checks = array(
    'php_version' => PHP_VERSION,
    'php_ok' => version_compare(PHP_VERSION, '8.0.0', '>='),
    'extensions' => array(
        'curl' => extension_loaded('curl'),
        'json' => extension_loaded('json'),
        'session' => extension_loaded('session'),
        'pdo' => extension_loaded('pdo'),
        'pdo_sqlite' => extension_loaded('pdo_sqlite'),
        'mbstring' => extension_loaded('mbstring'),
        'sqlsrv' => extension_loaded('sqlsrv'),
        'pdo_sqlsrv' => extension_loaded('pdo_sqlsrv'),
    ),
    'paths' => array(
        'root' => $root,
        'storage_exists' => is_dir($storage),
        'storage_writable' => qrex_health_writable($storage),
        'sessions_writable' => qrex_health_writable($sessionDir),
        'token_file_exists' => is_file($tokenFile),
        'token_file_has_value' => is_file($tokenFile) && trim((string)file_get_contents($tokenFile)) !== '',
    ),
    'files' => array(
        'api_php_mtime' => is_file(__DIR__ . '/api.php') ? date('c', filemtime(__DIR__ . '/api.php')) : null,
        'bootstrap_php_mtime' => is_file($root . '/app/bootstrap.php') ? date('c', filemtime($root . '/app/bootstrap.php')) : null,
        'turso_php_mtime' => is_file($root . '/app/TursoConnection.php') ? date('c', filemtime($root . '/app/TursoConnection.php')) : null,
    ),
);

$checks['summary'] = array(
    'can_run_app' => $checks['php_ok']
        && $checks['extensions']['curl']
        && $checks['extensions']['json']
        && $checks['extensions']['session']
        && $checks['extensions']['pdo']
        && $checks['paths']['storage_writable'],
    'needs_attention' => array(),
);

if (!$checks['php_ok']) $checks['summary']['needs_attention'][] = 'Use PHP 8.0 or newer. PHP 8.2 is recommended.';
if (!$checks['extensions']['curl']) $checks['summary']['needs_attention'][] = 'Enable PHP extension curl. Turso needs it.';
if (!$checks['extensions']['json']) $checks['summary']['needs_attention'][] = 'Enable PHP extension json.';
if (!$checks['extensions']['session']) $checks['summary']['needs_attention'][] = 'Enable PHP extension session.';
if (!$checks['extensions']['pdo']) $checks['summary']['needs_attention'][] = 'Enable PHP extension pdo.';
if (!$checks['paths']['storage_writable']) $checks['summary']['needs_attention'][] = 'Make quality/storage writable by Apache/PHP.';
if (!$checks['paths']['token_file_has_value'] && getenv('TURSO_AUTH_TOKEN') === '') $checks['summary']['needs_attention'][] = 'Configure TURSO_AUTH_TOKEN or quality/storage/turso-db-token.txt.';
if (!$checks['extensions']['sqlsrv'] || !$checks['extensions']['pdo_sqlsrv']) $checks['summary']['needs_attention'][] = 'Enable sqlsrv and pdo_sqlsrv for QAPQC Find order.';

if (isset($_GET['db'])) {
    $checks['database'] = array('ok' => false);
    try {
        require $root . '/app/bootstrap.php';
        $checks['database']['driver'] = db_driver();
        $checks['database']['user_count'] = (int)query('SELECT COUNT(*) FROM M_user')->fetchColumn();
        $checks['database']['ok'] = true;
    } catch (Throwable $e) {
        $checks['database']['error_type'] = get_class($e);
        $checks['database']['error'] = $e->getMessage();
    }
}

echo json_encode($checks, JSON_PRETTY_PRINT);
