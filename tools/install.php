<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

try {
    Database::install(db(), false);
    Database::seed(db(), master());

    $password = getenv('QUALITY_ADMIN_PASSWORD') ?: bin2hex(random_bytes(10));
    $email = getenv('QUALITY_ADMIN_EMAIL') ?: 'admin@quality.local';
    demand(strlen($password) >= 6, 'Administrator password must have at least 6 characters.');

    $createdAdmin = false;
    if ((int)query('SELECT COUNT(*) FROM M_user')->fetchColumn() === 0) {
        query(
            'INSERT INTO M_user (username,email,password_hash,full_name,role,active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)',
            [
                $email,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                'Quality Administrator',
                'admin',
                1,
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s'),
            ]
        );
        $createdAdmin = true;
    }

    file_put_contents(
        ROOT . '/storage/local-access.txt',
        "QREX local installation\n" .
        "URL: http://localhost/CPETPE_dev/quality/public/\n" .
        ($createdAdmin ? "Email: $email\nTemporary password: $password\nChange this password after first sign-in.\n" : "Administrator already exists; no password was changed.\n") .
        "Database: " . ($settings['dsn'] ?? 'Turso/libSQL') . "\n"
    );

    echo "Installed QREX Turso/libSQL database.\n";
    echo "Database: " . ($settings['dsn'] ?? 'Turso/libSQL') . "\n";
    echo "Specification parameters: " . query('SELECT COUNT(*) FROM M_masterlist_specifications')->fetchColumn() . "\n";
    echo ($createdAdmin ? "Access details: quality/storage/local-access.txt\n" : "Administrator already exists; no password was changed.\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Install failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
