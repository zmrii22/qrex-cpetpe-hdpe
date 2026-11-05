<?php
declare(strict_types=1);
$file = __DIR__ . '/secrets.local.php';
$local = is_file($file) ? require $file : [];
return [
 'name' => 'QREX',
 'dsn' => getenv('QUALITY_DSN') ?: (getenv('TURSO_DATABASE_URL') ?: ('sqlite:' . dirname(__DIR__) . '/storage/demo.sqlite')),
 'auth_token' => getenv('TURSO_AUTH_TOKEN') ?: ($local['turso_auth_token'] ?? ''),
 'user' => getenv('QUALITY_DB_USER') ?: '',
 'password' => getenv('QUALITY_DB_PASSWORD') ?: '',
 'qapqc' => [
  'dsn' => getenv('QREX_QAPQC_DSN') ?: '',
  'user' => getenv('QREX_QAPQC_USER') ?: '',
  'password' => getenv('QREX_QAPQC_PASSWORD') ?: '',
  'enabled' => getenv('QREX_QAPQC_ENABLED') === '1',
 ],
 'timezone' => 'Asia/Singapore',
];
