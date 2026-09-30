<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class AuditService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function record(
        ?int $userId,
        string $action,
        string $table,
        string|int|null $recordId,
        ?array $before = null,
        ?array $after = null
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, table_name, record_id, before_json, after_json, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $userId,
            $action,
            $table,
            $recordId,
            $before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            $after ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? 'cli',
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'cli'), 0, 255),
        ]);
    }
}
