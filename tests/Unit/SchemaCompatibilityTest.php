<?php
namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class SchemaCompatibilityTest extends TestCase
{
    public function test_business_dates_compile_without_implicit_timestamp_defaults(): void
    {
        // Compile the actual migration without connecting to or changing any database.
        $connection = new class(function () {
            throw new \LogicException('This test must not connect to a database.');
        }, 'schema_test', '', ['driver' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'engine' => 'InnoDB']) extends MySqlConnection {
            public function isMaria() { return true; }
            public function getServerVersion(): string { return '10.4.32'; }
        };
        $connection->useDefaultSchemaGrammar();
        $collector = new class($connection) {
            public array $sql = [];
            public function __construct(private MySqlConnection $connection) {}
            public function create(string $table, \Closure $callback): void
            {
                $blueprint = new Blueprint($this->connection, $table);
                $blueprint->create();
                $callback($blueprint);
                $this->sql[$table] = implode(";\n", $blueprint->toSql());
            }
        };
        $previous = Facade::getFacadeApplication();
        Facade::setFacadeApplication(new Container);
        Schema::swap($collector);
        try {
            $migration = require __DIR__.'/../../database/migrations/2026_11_22_000001_create_pangantani_tables.php';
            $migration->up();
            $this->assertCount(21, $collector->sql);
            foreach ($collector->sql as $sql) {
                $this->assertDoesNotMatchRegularExpression('/`[^`]+` timestamp(?:\(\d+\))? not null/i', $sql);
            }
            foreach (['starts_at', 'ends_at'] as $field) {
                $this->assertStringContainsString('`'.$field.'` datetime not null', $collector->sql['pickup_slots']);
            }
            foreach (['pickup_starts_at', 'pickup_ends_at', 'payment_due_at'] as $field) {
                $this->assertStringContainsString('`'.$field.'` datetime not null', $collector->sql['orders']);
            }
            $this->assertStringContainsString('`starts_at` datetime not null', $collector->sql['price_steps']);
            $this->assertStringContainsString('`needed_at` datetime not null', $collector->sql['food_needs']);
            $reference = file_get_contents(__DIR__.'/../../database/sql/schema.mysql.sql');
            $this->assertDoesNotMatchRegularExpression('/\bTIMESTAMP NOT NULL\b/i', $reference);
        } finally {
            Facade::clearResolvedInstance('db.schema');
            Facade::setFacadeApplication($previous);
        }
    }
}
