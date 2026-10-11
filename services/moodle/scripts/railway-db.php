<?php
// Database helper for the Moodle boot script (runs without loading Moodle).
//
//   php railway-db.php wait       wait until Postgres accepts connections (MOODLE_DB_WAIT_SECONDS)
//   php railway-db.php installed  exit 0 if Moodle tables exist, 3 if the database is empty
//
// Exit 1 on any error.

declare(strict_types=1);

const EXIT_NOT_INSTALLED = 3;

function env_value(string $name, string $default = ''): string
{
    $value = getenv($name);
    return ($value === false || $value === '') ? $default : $value;
}

function connection_string(): string
{
    $parts = [
        'host' => env_value('MOODLE_DB_HOST', 'localhost'),
        'port' => env_value('MOODLE_DB_PORT', '5432'),
        'dbname' => env_value('MOODLE_DB_NAME', 'moodle'),
        'user' => env_value('MOODLE_DB_USER', 'postgres'),
        'password' => env_value('MOODLE_DB_PASSWORD'),
        'connect_timeout' => '5',
    ];
    $pairs = [];
    foreach ($parts as $key => $value) {
        $pairs[] = $key . "='" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }
    return implode(' ', $pairs);
}

function try_connect(): ?\PgSql\Connection
{
    $connection = @pg_connect(connection_string(), PGSQL_CONNECT_FORCE_NEW);
    return $connection === false ? null : $connection;
}

function wait_for_database(): int
{
    $deadline = time() + (int) env_value('MOODLE_DB_WAIT_SECONDS', '600');
    $attempt = 0;
    while (true) {
        $attempt++;
        $connection = try_connect();
        if ($connection !== null) {
            pg_close($connection);
            fwrite(STDOUT, "[railway-db] database reachable\n");
            return 0;
        }
        if (time() >= $deadline) {
            fwrite(STDERR, "[railway-db] database not reachable, giving up\n");
            return 1;
        }
        fwrite(STDOUT, "[railway-db] waiting for database (attempt {$attempt})\n");
        sleep(3);
    }
}

function moodle_is_installed(): int
{
    $connection = try_connect();
    if ($connection === null) {
        fwrite(STDERR, "[railway-db] cannot connect to the database\n");
        return 1;
    }
    $table = env_value('MOODLE_DB_PREFIX', 'mdl_') . 'config';
    $result = pg_query_params(
        $connection,
        'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = $1',
        [$table]
    );
    if ($result === false) {
        fwrite(STDERR, '[railway-db] query failed: ' . pg_last_error($connection) . "\n");
        pg_close($connection);
        return 1;
    }
    $exists = pg_num_rows($result) > 0;
    pg_close($connection);
    return $exists ? 0 : EXIT_NOT_INSTALLED;
}

$command = $argv[1] ?? '';
exit(match ($command) {
    'wait' => wait_for_database(),
    'installed' => moodle_is_installed(),
    default => (function (): int {
        fwrite(STDERR, "usage: php railway-db.php wait|installed\n");
        return 1;
    })(),
});
