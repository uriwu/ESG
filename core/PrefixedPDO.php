<?php
declare(strict_types=1);

namespace Core;

use PDO;

final class PrefixedPDO extends PDO
{
    private string $tablePrefix;

    private const TABLES = [
        'organizations', 'roles', 'users', 'ghg_sources', 'emission_factors',
        'activity_data', 'workflow_tasks', 'attachments', 'social_metrics',
        'governance_records', 'materiality_topics', 'audit_logs',
        'login_attempts', 'api_keys',
    ];

    public function __construct(string $dsn, ?string $username, ?string $password, array $options, string $tablePrefix = '')
    {
        $this->tablePrefix = self::sanitizePrefix($tablePrefix);
        parent::__construct($dsn, $username, $password, $options);
    }

    public static function sanitizePrefix(string $prefix): string
    {
        if ($prefix === '') return '';
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,31}$/', $prefix)) {
            throw new \InvalidArgumentException('DB_PREFIX 只能包含英文字母、數字及底線，且必須以字母開頭。');
        }
        return $prefix;
    }

    public static function rewriteSql(string $sql, string $prefix): string
    {
        $prefix = self::sanitizePrefix($prefix);
        if ($prefix === '') return $sql;
        foreach (self::TABLES as $table) {
            $pattern = '/(?<![A-Za-z0-9_])`?' . preg_quote($table, '/') . '`?(?![A-Za-z0-9_])/i';
            $sql = (string) preg_replace($pattern, '`' . $prefix . $table . '`', $sql);
        }
        return $sql;
    }

    public function prepare($query, $options = [])
    {
        return parent::prepare(self::rewriteSql((string) $query, $this->tablePrefix), $options);
    }

    public function query($query, ...$fetchModeArgs)
    {
        return parent::query(self::rewriteSql((string) $query, $this->tablePrefix), ...$fetchModeArgs);
    }

    public function exec($statement)
    {
        return parent::exec(self::rewriteSql((string) $statement, $this->tablePrefix));
    }
}

