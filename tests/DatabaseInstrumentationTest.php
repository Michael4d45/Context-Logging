<?php

namespace Michael4d45\ContextLogging\Tests;

use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Michael4d45\ContextLogging\ContextStore;
use Michael4d45\ContextLogging\Database\Connections\CapturingSQLiteConnection;
use Michael4d45\ContextLogging\Database\DatabaseInstrumentation;
use Michael4d45\ContextLogging\Database\ResultCapturer;
use PHPUnit\Framework\Attributes\Test;

class DatabaseInstrumentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DatabaseInstrumentation::restoreResolvers();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        config()->set('context-logging.log.db', false);
        config()->set('context-logging.database.capture_results', false);
    }

    protected function tearDown(): void
    {
        DatabaseInstrumentation::restoreResolvers();
        DB::purge('sqlite');
        parent::tearDown();
    }

    protected function enableDbLogging(bool $captureResults = false): void
    {
        DatabaseInstrumentation::restoreResolvers();

        config()->set('context-logging.log.db', true);
        config()->set('context-logging.database.capture_results', $captureResults);

        // Fresh instrumentation instance so resolver/listener flags match this test.
        $this->app->forgetInstance(DatabaseInstrumentation::class);
        $this->app->make(DatabaseInstrumentation::class)->register();

        DB::purge('sqlite');
        DB::reconnect('sqlite');
    }

    protected function createUsersTable(): void
    {
        Schema::create('users', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('password')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function sqlEvents(ContextStore $store): array
    {
        return array_values(array_filter(
            $store->getPayload()['events'],
            static fn (array $event): bool => ($event['message'] ?? null) === 'sql',
        ));
    }

    #[Test]
    public function it_logs_sql_without_results_when_capture_is_off(): void
    {
        $this->enableDbLogging(false);
        $this->createUsersTable();

        $store = $this->app->make(ContextStore::class);
        $store->initialize();

        DB::table('users')->insert(['name' => 'Ada', 'password' => 'secret']);
        $rows = DB::select('select * from users');

        $this->assertNotEmpty($rows);
        $this->assertInstanceOf(SQLiteConnection::class, DB::connection());
        $this->assertNotInstanceOf(CapturingSQLiteConnection::class, DB::connection());

        $events = $this->sqlEvents($store);
        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $this->assertArrayHasKey('SQL', $event['context']);
            $this->assertArrayHasKey('execution_time', $event['context']);
            $this->assertArrayHasKey('trace', $event['context']);
            $this->assertArrayNotHasKey('result', $event['context']);
        }
    }

    #[Test]
    public function it_captures_select_rows_when_enabled(): void
    {
        $this->enableDbLogging(true);
        $this->createUsersTable();

        $store = $this->app->make(ContextStore::class);
        $store->initialize();

        DB::table('users')->insert([
            ['name' => 'Ada', 'password' => 'secret'],
            ['name' => 'Grace', 'password' => 'hunter2'],
        ]);

        DB::select('select id, name, password from users order by id');

        $this->assertInstanceOf(CapturingSQLiteConnection::class, DB::connection());

        $events = $this->sqlEvents($store);
        $selectEvents = array_values(array_filter(
            $events,
            static fn (array $event): bool => str_contains(strtolower((string) ($event['context']['SQL'] ?? '')), 'select'),
        ));

        $this->assertNotEmpty($selectEvents);
        $result = $selectEvents[array_key_last($selectEvents)]['context']['result'] ?? null;

        $this->assertIsArray($result);
        $this->assertSame(2, $result['row_count']);
        $this->assertSame(2, $result['returned_rows']);
        $this->assertFalse($result['truncated']);
        $this->assertSame('Ada', $result['rows'][0]['name']);
        $this->assertSame('[redacted]', $result['rows'][0]['password']);
    }

    #[Test]
    public function it_captures_affected_rows_for_writes(): void
    {
        $this->enableDbLogging(true);
        $this->createUsersTable();

        $store = $this->app->make(ContextStore::class);
        $store->initialize();

        DB::table('users')->insert(['name' => 'Ada', 'password' => null]);
        DB::affectingStatement('update users set name = ? where name = ?', ['Augusta', 'Ada']);

        $events = $this->sqlEvents($store);
        $updateEvents = array_values(array_filter(
            $events,
            static fn (array $event): bool => str_contains(strtolower((string) ($event['context']['SQL'] ?? '')), 'update'),
        ));

        $this->assertNotEmpty($updateEvents);
        $result = $updateEvents[array_key_last($updateEvents)]['context']['result'] ?? null;

        $this->assertIsArray($result);
        $this->assertSame(1, $result['affected_rows']);
    }

    #[Test]
    public function it_respects_max_rows_and_marks_truncated(): void
    {
        config()->set('context-logging.database.max_rows', 2);
        $this->enableDbLogging(true);
        $this->createUsersTable();

        $store = $this->app->make(ContextStore::class);
        $store->initialize();

        DB::table('users')->insert([
            ['name' => 'A', 'password' => null],
            ['name' => 'B', 'password' => null],
            ['name' => 'C', 'password' => null],
        ]);

        DB::select('select name from users order by id');

        $events = $this->sqlEvents($store);
        $selectEvents = array_values(array_filter(
            $events,
            static fn (array $event): bool => str_contains(strtolower((string) ($event['context']['SQL'] ?? '')), 'select name'),
        ));

        $result = $selectEvents[array_key_last($selectEvents)]['context']['result'];

        $this->assertTrue($result['truncated']);
        $this->assertSame(3, $result['row_count']);
        $this->assertSame(2, $result['returned_rows']);
    }

    #[Test]
    public function it_does_not_log_when_db_logging_is_disabled(): void
    {
        config()->set('context-logging.log.db', false);
        config()->set('context-logging.database.capture_results', true);

        $this->app->forgetInstance(DatabaseInstrumentation::class);
        $this->app->make(DatabaseInstrumentation::class)->register();

        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $this->createUsersTable();

        $store = $this->app->make(ContextStore::class);
        $store->initialize();

        DB::select('select 1 as one');

        $this->assertSame([], $this->sqlEvents($store));
        $this->assertNotInstanceOf(CapturingSQLiteConnection::class, DB::connection());
    }

    #[Test]
    public function result_capturer_skips_pdo_statements(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $statement = $pdo->query('select 1');
        $this->assertInstanceOf(\PDOStatement::class, $statement);

        $captured = ResultCapturer::capture($statement);

        $this->assertSame('cursor', $captured['skipped']);
    }

    #[Test]
    public function result_capturer_truncates_long_columns(): void
    {
        config()->set('context-logging.database.max_column_length', 5);
        config()->set('context-logging.database.redact_fields', []);

        $captured = ResultCapturer::capture([
            (object) ['name' => 'abcdefghij'],
        ]);

        $this->assertSame('abcde…', $captured['rows'][0]['name']);
    }
}
