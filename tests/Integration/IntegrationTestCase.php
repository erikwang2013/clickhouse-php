<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Integration;

use Erikwang2013\ClickHouse\ClickHouse;
use Erikwang2013\ClickHouse\Client\ClientInterface;
use Erikwang2013\ClickHouse\Client\Manager;
use Erikwang2013\ClickHouse\Exceptions\ConnectionException;
use Erikwang2013\ClickHouse\Schema\Blueprint;
use Erikwang2013\ClickHouse\Schema\Builder as SchemaBuilder;
use Erikwang2013\ClickHouse\Support\Quoter;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that talk to a real ClickHouse server.
 *
 * Connection parameters come from the CLICKHOUSE_* environment variables (see
 * Manager::fromEnv()), defaulting to 127.0.0.1:8123 as user `default` with an
 * empty password.
 *
 * setUpBeforeClass() probes with a real `SELECT 1`, then picks one of three
 * outcomes, so a developer machine never turns into a wall of red:
 *
 *  - socket refused              -> skip (nothing to test against)
 *  - socket answered, no explicit credentials configured -> skip with the
 *    server's error, e.g. somebody else's password-protected ClickHouse
 *  - socket answered, CLICKHOUSE_USER/CLICKHOUSE_PASS set -> throw, because
 *    credentials given on purpose are expected to work
 *
 * CLICKHOUSE_INTEGRATION_REQUIRED=1 forces that last behaviour for every
 * failure, so CI cannot report green with zero integration coverage. Run the
 * suite with:
 *
 *     vendor/bin/phpunit --group integration
 */
abstract class IntegrationTestCase extends TestCase
{
    protected static ClientInterface $client;
    protected static SchemaBuilder $schema;

    /** Tables created via table(), dropped again in tearDown(). */
    private array $tables = [];

    public static function setUpBeforeClass(): void
    {
        $host = self::env('CLICKHOUSE_HOST', '127.0.0.1');
        $port = (int) self::env('CLICKHOUSE_PORT', '8123');

        [$reachable, $reason] = self::probe($host, $port);

        if ($reachable && $reason === null) {
            return;
        }

        $message = sprintf(
            'No usable ClickHouse at %s:%d (%s). Set CLICKHOUSE_HOST/CLICKHOUSE_PORT/'
            . 'CLICKHOUSE_USER/CLICKHOUSE_PASS to point at one, or start one with '
            . '`docker run -p 8123:8123 clickhouse/clickhouse-server`.',
            $host,
            $port,
            $reason,
        );

        // A green run with zero integration coverage is exactly how the
        // unconditional " FORMAT JSON" bug survived a fully green stub suite,
        // so CI sets this to turn "nothing to test against" into a failure.
        if (self::enabled('CLICKHOUSE_INTEGRATION_REQUIRED')) {
            throw new ConnectionException(
                $message . ' CLICKHOUSE_INTEGRATION_REQUIRED is set, so skipping is not allowed.',
            );
        }

        // Credentials were supplied on purpose (CI, or a developer pointing at a
        // specific server): a rejected password is a mistake worth seeing. With
        // no credentials configured we probably just bumped into somebody
        // else's password-protected ClickHouse on a dev machine.
        if ($reachable && self::credentialsConfigured()) {
            throw new ConnectionException(
                $message . ' CLICKHOUSE_USER/CLICKHOUSE_PASS are set, so those credentials are'
                . ' expected to work.',
            );
        }

        self::markTestSkipped($message);
    }

    /**
     * Probes the server with a real query rather than a bare socket: a server
     * that accepts the connection but rejects our credentials, or lacks the
     * database, is not usable.
     *
     * @return array{0: bool, 1: ?string} [socket answered, failure reason]
     */
    private static function probe(string $host, int $port): array
    {
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $host, $port),
            $errno,
            $error,
            1.0,
        );

        if ($socket === false) {
            return [false, $error === '' ? 'connection refused' : $error];
        }

        fclose($socket);

        try {
            ClickHouse::setManager(Manager::fromEnv());
            static::$client = ClickHouse::getManager()->connection();
            static::$schema = new SchemaBuilder(static::$client);
            static::$client->query('SELECT 1');
        } catch (\Throwable $e) {
            return [true, self::describe($e)];
        }

        return [true, null];
    }

    /**
     * Short one-line form of a connection/query failure, HTTP status first.
     */
    private static function describe(\Throwable $e): string
    {
        $code = $e->getCode();
        $detail = mb_substr(trim((string) preg_replace('/\s+/', ' ', $e->getMessage())), 0, 160);

        return is_int($code) && $code > 0 ? "{$code}: {$detail}" : $detail;
    }

    private static function credentialsConfigured(): bool
    {
        return getenv('CLICKHOUSE_USER') !== false || getenv('CLICKHOUSE_PASS') !== false;
    }

    private static function enabled(string $name): bool
    {
        $value = getenv($name);

        return $value !== false && $value !== '' && filter_var($value, FILTER_VALIDATE_BOOL);
    }

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            if (!isset(static::$client)) {
                break;
            }

            try {
                static::$client->query('DROP TABLE IF EXISTS ' . Quoter::table($table));
            } catch (\Throwable) {
                // Never mask the failure that ended the test.
            }
        }

        $this->tables = [];

        parent::tearDown();
    }

    /**
     * A table name unique to this test run, dropped automatically in tearDown().
     */
    protected function table(string $prefix = 'it'): string
    {
        $name = $prefix . '_' . bin2hex(random_bytes(5));
        $this->tables[] = $name;

        return $name;
    }

    /**
     * Table shape shared by the query/ORM tests: id UInt32, name String,
     * score Float64, active UInt8, note Nullable(String).
     */
    protected function makeTable(string $table): void
    {
        static::$schema->create($table, function (Blueprint $blueprint) {
            $blueprint->uint32('id');
            $blueprint->string('name');
            $blueprint->float64('score');
            $blueprint->uint8('active');
            $blueprint->nullable('note', 'String');
            $blueprint->engine('MergeTree')->orderBy(['id']);
        });
    }

    /**
     * Inserts five rows into a makeTable() table. `note` stays NULL.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function seedTable(string $table): array
    {
        $rows = [
            ['id' => 1, 'name' => 'alice', 'score' => 10.5, 'active' => 1],
            ['id' => 2, 'name' => 'bob', 'score' => 20.5, 'active' => 0],
            ['id' => 3, 'name' => 'carol', 'score' => 30.5, 'active' => 1],
            ['id' => 4, 'name' => 'dave', 'score' => 40.5, 'active' => 0],
            ['id' => 5, 'name' => 'erin', 'score' => 50.5, 'active' => 1],
        ];

        static::$client->insert($table, $rows);

        return $rows;
    }

    protected static function database(): string
    {
        return self::env('CLICKHOUSE_DB', 'default');
    }

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }
}
