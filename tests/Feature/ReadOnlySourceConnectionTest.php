<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReadOnlySourceConnectionTest extends TestCase
{
    #[DataProvider('sourceConnectionNames')]
    public function test_source_connections_allow_select_queries(string $connectionName): void
    {
        $connection = $this->guardedConnection($connectionName);

        $result = $connection->select('select 1 as value');

        $this->assertSame(1, $result[0]->value);
    }

    #[DataProvider('sourceConnectionNames')]
    public function test_source_connections_reject_write_queries_before_execution(string $connectionName): void
    {
        $connection = $this->guardedConnection($connectionName);

        $this->expectException(LogicException::class);
        $connection->statement('create table forbidden_write (id integer)');
    }

    #[DataProvider('sourceConnectionNames')]
    public function test_source_connections_reject_multiple_statements(string $connectionName): void
    {
        $connection = $this->guardedConnection($connectionName);

        $this->expectException(LogicException::class);
        $connection->select('select 1; delete from projects');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sourceConnectionNames(): array
    {
        return [
            'M&E source' => ['mne_source'],
            'projects source' => ['projects_source'],
        ];
    }

    private function guardedConnection(string $connectionName): Connection
    {
        $connection = new Connection(
            new PDO('sqlite::memory:'),
            ':memory:',
            '',
            ['name' => $connectionName],
        );

        event(new ConnectionEstablished($connection));

        return $connection;
    }
}
