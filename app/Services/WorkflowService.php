<?php
declare(strict_types=1);

namespace App\Services;

use DomainException;

final class WorkflowService
{
    private const TRANSITIONS = [
        'draft' => ['submit' => 'pending_review'],
        'rejected' => ['submit' => 'pending_review'],
        'pending_review' => ['approve' => 'pending_approval', 'reject' => 'rejected'],
        'pending_approval' => ['approve' => 'approved', 'reject' => 'rejected'],
        'approved' => [],
    ];

    public function transition(string $current, string $action): string
    {
        $next = self::TRANSITIONS[$current][$action] ?? null;
        if ($next === null) {
            throw new DomainException("不允許從 {$current} 執行 {$action}。已核准資料不得直接修改。");
        }
        return $next;
    }

    public function allowedActions(string $status): array
    {
        return array_keys(self::TRANSITIONS[$status] ?? []);
    }
}

