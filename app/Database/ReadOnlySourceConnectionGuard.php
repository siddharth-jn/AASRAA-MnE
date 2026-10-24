<?php

namespace App\Database;

use Illuminate\Database\Connection;
use LogicException;

class ReadOnlySourceConnectionGuard
{
    /** @var list<string> */
    private const array CONNECTION_NAMES = [
        'mne_source',
        'projects_source',
    ];

    /** @var list<string> */
    private const array ALLOWED_STATEMENTS = [
        'select',
        'show',
        'describe',
        'desc',
        'explain',
    ];

    public function protect(Connection $connection): void
    {
        $connectionName = $connection->getName();

        if (! is_string($connectionName) || ! in_array($connectionName, self::CONNECTION_NAMES, true)) {
            return;
        }

        $connection->beforeExecuting(function (string $query) use ($connectionName): void {
            $this->ensureQueryIsReadOnly($query, $connectionName);
        });
    }

    public function ensureQueryIsReadOnly(string $query, string $connectionName): void
    {
        $statement = trim($query);
        $statementWithoutTrailingTerminator = rtrim($statement, ';');

        if ($statementWithoutTrailingTerminator === '' || str_contains($statementWithoutTrailingTerminator, ';')) {
            throw new LogicException("The [$connectionName] connection only permits single read-only statements.");
        }

        preg_match('/\A([a-z]+)/i', $statementWithoutTrailingTerminator, $matches);
        $statementType = strtolower($matches[1] ?? 'unknown');

        if (! in_array($statementType, self::ALLOWED_STATEMENTS, true)) {
            throw new LogicException("The [$connectionName] connection is read-only; [$statementType] statements are not permitted.");
        }

        if (preg_match('/\b(?:into\s+(?:out|dump)file|for\s+update|lock\s+in\s+share\s+mode)\b/i', $statementWithoutTrailingTerminator) === 1) {
            throw new LogicException("The [$connectionName] connection does not permit file output or locking reads.");
        }
    }
}
