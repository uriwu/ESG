<?php
declare(strict_types=1);

use Core\Database;
use Core\Env;

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function env(string $key, mixed $default = null): mixed
{
    return Env::get($key, $default);
}

function db(): PDO
{
    return Database::connection();
}

function base_path(string $path = ''): string
{
    return dirname(__DIR__) . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, '\\/') : '');
}

function url(string $path = '/'): string
{
    return rtrim((string) env('APP_URL', ''), '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('CSRF 驗證失敗，請返回上一頁重新送出。');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = compact('type', 'message');
}

function consume_flash(): array
{
    $items = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $items;
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_auth(): array
{
    $user = current_user();
    if (!$user) {
        redirect('/login');
    }
    return $user;
}

function has_role(string ...$roles): bool
{
    $user = current_user();
    return $user && in_array($user['role_code'], $roles, true);
}

function require_role(string ...$roles): void
{
    require_auth();
    if (!has_role(...$roles)) {
        http_response_code(403);
        exit('沒有權限執行此操作。');
    }
}

function organization_scope(string $column = 'org_id'): array
{
    $user = current_user();
    if (!$user || has_role('ROLE_SUPER_ADMIN', 'ROLE_ESG_COMMITTEE', 'ROLE_AUDITOR')) {
        return ['sql' => '1=1', 'params' => []];
    }
    return ['sql' => $column . ' = ?', 'params' => [(int) $user['org_id']]];
}

function render(string $view, array $data = []): never
{
    extract($data, EXTR_SKIP);
    $viewFile = base_path("app/Views/{$view}.php");
    if (!is_file($viewFile)) {
        throw new RuntimeException("View not found: {$view}");
    }
    ob_start();
    require $viewFile;
    $content = (string) ob_get_clean();
    require base_path('app/Views/layout.php');
    exit;
}

function input(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

function parse_attachments(?string $value): array
{
    if (!$value) return [];
    $items = [];
    foreach (explode('||', $value) as $entry) {
        [$id, $name] = array_pad(explode('::', $entry, 2), 2, '');
        if (ctype_digit($id) && $name !== '') $items[] = ['id' => (int) $id, 'name' => $name];
    }
    return $items;
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload + ['timestamp' => time()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
