<?php
declare(strict_types=1);

require dirname(__DIR__) . '/core/Env.php';
require dirname(__DIR__) . '/core/PrefixedPDO.php';
require dirname(__DIR__) . '/core/Database.php';

use Core\Env;
use Core\PrefixedPDO;
Env::load(dirname(__DIR__) . '/.env');

$host = (string) Env::get('DB_HOST', '127.0.0.1');
$port = (string) Env::get('DB_PORT', '3306');
$name = preg_replace('/[^a-zA-Z0-9_]/', '', (string) Env::get('DB_DATABASE', 'esg_system'));
$user = (string) Env::get('DB_USERNAME', 'root');
$pass = (string) Env::get('DB_PASSWORD', '');

try {
    $pdo = new PrefixedPDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ], (string) Env::get('DB_PREFIX', ''));
    $schema = file_get_contents(__DIR__ . '/schema.sql');
    $schema = preg_replace('/CREATE DATABASE IF NOT EXISTS esg_system/', "CREATE DATABASE IF NOT EXISTS `{$name}`", $schema);
    $schema = preg_replace('/USE esg_system;/', "USE `{$name}`;", $schema);
    $pdo->exec((string) $schema);
    $pdo->exec("USE `{$name}`");

    $email = $argv[1] ?? 'admin@esg.local';
    $password = $argv[2] ?? 'ChangeMe123!';
    $roleId = (int) $pdo->query("SELECT role_id FROM roles WHERE role_code='ROLE_SUPER_ADMIN'")->fetchColumn();
    $orgId = (int) $pdo->query("SELECT org_id FROM organizations WHERE org_code='HQ'")->fetchColumn();
    $stmt = $pdo->prepare('INSERT INTO users (org_id, role_id, name, email, password_hash, must_change_password) VALUES (?, ?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), must_change_password=1');
    $stmt->execute([$orgId, $roleId, '系統管理員', $email, password_hash($password, PASSWORD_DEFAULT)]);

    $iotKey = (string) Env::get('IOT_API_KEY', 'replace-with-an-iot-api-key');
    $stmt = $pdo->prepare("INSERT INTO api_keys (name, key_hash) VALUES ('IoT Gateway', ?) ON DUPLICATE KEY UPDATE active=1");
    $stmt->execute([hash('sha256', $iotKey)]);

    echo "Installation completed.\nAdmin: {$email}\nTemporary password: {$password}\nPlease change it after the first login.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Installation failed: {$e->getMessage()}\n");
    exit(1);
}
