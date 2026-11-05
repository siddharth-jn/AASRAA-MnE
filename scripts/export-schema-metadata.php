<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$application = require __DIR__.'/../bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

$connection = DB::connection();
$databaseName = $connection->getDatabaseName();
$tables = collect($connection->select(
    <<<'SQL'
        select table_name, engine, table_collation, table_comment, data_length, index_length
        from information_schema.tables
        where table_schema = database() and table_type = 'BASE TABLE'
        order by table_name
    SQL,
))->map(function (object $table) use ($connection): array {
    $table = array_change_key_case((array) $table, CASE_LOWER);
    $tableName = (string) $table['table_name'];
    $quotedTableName = '`'.str_replace('`', '``', $tableName).'`';
    $rowCount = (int) $connection->scalar("select count(*) from {$quotedTableName}");
    $columns = collect($connection->select(
        <<<'SQL'
            select column_name, ordinal_position, column_default, is_nullable, data_type, column_type,
                   character_maximum_length, numeric_precision, numeric_scale, column_key, extra, column_comment
            from information_schema.columns
            where table_schema = database() and table_name = ?
            order by ordinal_position
        SQL,
        [$tableName],
    ))->map(fn (object $column): array => array_change_key_case((array) $column, CASE_LOWER))->all();
    $foreignKeys = collect($connection->select(
        <<<'SQL'
            select constraint_name, column_name, referenced_table_name, referenced_column_name
            from information_schema.key_column_usage
            where table_schema = database() and table_name = ? and referenced_table_name is not null
            order by constraint_name, ordinal_position
        SQL,
        [$tableName],
    ))->map(fn (object $key): array => array_change_key_case((array) $key, CASE_LOWER))->all();
    $indexes = collect($connection->select(
        <<<'SQL'
            select index_name, non_unique, seq_in_index, column_name
            from information_schema.statistics
            where table_schema = database() and table_name = ?
            order by index_name, seq_in_index
        SQL,
        [$tableName],
    ))->map(fn (object $index): array => array_change_key_case((array) $index, CASE_LOWER))
        ->groupBy('index_name')->map(function ($items, string $indexName): array {
            $first = $items->first();

            return [
                'name' => $indexName,
                'unique' => ! (bool) $first['non_unique'],
                'columns' => $items->pluck('column_name')->all(),
            ];
        })->values()->all();

    return [
        'name' => $tableName,
        'row_count' => $rowCount,
        'engine' => $table['engine'],
        'collation' => $table['table_collation'],
        'comment' => $table['table_comment'],
        'data_bytes' => (int) $table['data_length'],
        'index_bytes' => (int) $table['index_length'],
        'columns' => $columns,
        'foreign_keys' => $foreignKeys,
        'indexes' => $indexes,
    ];
});

echo json_encode([
    'generated_at' => now()->toIso8601String(),
    'connection' => config('database.default'),
    'database' => $databaseName,
    'driver' => $connection->getDriverName(),
    'server_version' => $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION),
    'tables' => $tables->all(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
