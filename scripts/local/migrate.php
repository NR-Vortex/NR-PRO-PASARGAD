<?php

declare(strict_types=1);

use NRPro\Pasargad\Core\Config\Config;
use NRPro\Pasargad\Core\Config\Environment;
use NRPro\Pasargad\Core\Database\Connection;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$command = $argv[1] ?? '--status';

if (
    !in_array($command, ['--status', '--run'], true)
    || count($argv) > 2
) {
    fwrite(
        STDERR,
        "Usage: php scripts/local/migrate.php [--status|--run]\n"
    );
    exit(2);
}

$root = dirname(__DIR__, 2);

require_once $root . '/vendor/autoload.php';

$pdo = null;

try {

    /*
    |------------------------------------------------------------
    | LOAD ENVIRONMENT
    |------------------------------------------------------------
    */

    $env = new Environment(
        $root . '/.env'
    );

    $env->load();

    /*
    |------------------------------------------------------------
    | DATABASE CONFIGURATION
    |------------------------------------------------------------
    */

    $config = new Config([
        'database' => [
            'host' => $env->get('DB_HOST', '127.0.0.1'),
            'port' => $env->get('DB_PORT', '5432'),
            'name' => $env->get('DB_DATABASE', 'nr_pro_pasargad'),
            'username' => $env->get('DB_USERNAME'),
            'password' => $env->get('DB_PASSWORD'),
            'sslmode' => $env->get('DB_SSLMODE', 'disable'),
            'connect_timeout' => $env->get('DB_CONNECT_TIMEOUT', '5'),
        ],
    ]);

    /*
    |------------------------------------------------------------
    | DISCOVER MIGRATIONS
    |------------------------------------------------------------
    */

    $files = glob(
        $root . '/database/migrations/*.php'
    );

    if ($files === false) {
        throw new RuntimeException(
            'Cannot read migration directory.'
        );
    }

    sort($files, SORT_STRING);

    $migrations = [];

    foreach ($files as $file) {

        $name = basename($file);

        if (
            !preg_match(
                '/^\d{8}_\d{6}_[a-z0-9_]+\.php$/',
                $name
            )
        ) {
            throw new RuntimeException(
                "Invalid migration filename: {$name}"
            );
        }

        $checksum = hash_file(
            'sha256',
            $file
        );

        if ($checksum === false) {
            throw new RuntimeException(
                "Cannot hash migration: {$name}"
            );
        }

        $migrations[$name] = [
            'file' => $file,
            'checksum' => $checksum,
        ];
    }

    /*
    |------------------------------------------------------------
    | CONNECT DATABASE
    |------------------------------------------------------------
    */

    $database = new Connection($config);

    $pdo = $database->pdo();

    /*
    |------------------------------------------------------------
    | MIGRATION STATUS
    |------------------------------------------------------------
    */

    if ($command === '--status') {

        $exists = $pdo->query(
            "SELECT to_regclass('public.schema_migrations')"
        )->fetchColumn();

        $applied = $exists
            ? $pdo->query(
                'SELECT name, checksum FROM public.schema_migrations'
            )->fetchAll(PDO::FETCH_KEY_PAIR)
            : [];

        $problem = false;

        foreach ($migrations as $name => $migration) {

            $status = 'PENDING';

            if (isset($applied[$name])) {

                $status = hash_equals(
                    $applied[$name],
                    $migration['checksum']
                ) ? 'APPLIED' : 'CHANGED';

                if ($status === 'CHANGED') {
                    $problem = true;
                }
            }

            echo "[{$status}] {$name}\n";
        }

        foreach ($applied as $name => $checksum) {

            if (!isset($migrations[$name])) {

                echo "[MISSING FILE] {$name}\n";

                $problem = true;
            }
        }

        if ($migrations === []) {
            echo "[INFO] No migration files found.\n";
        }

        exit($problem ? 1 : 0);
    }

    /*
    |------------------------------------------------------------
    | BEGIN TRANSACTION
    |------------------------------------------------------------
    */

    $pdo->beginTransaction();

    /*
    |------------------------------------------------------------
    | PREVENT CONCURRENT MIGRATION RUNS
    |------------------------------------------------------------
    */

    $pdo->query(
        'SELECT pg_advisory_xact_lock(20261031, 1)'
    );

    /*
    |------------------------------------------------------------
    | MIGRATION HISTORY TABLE
    |------------------------------------------------------------
    */

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS public.schema_migrations (
    name VARCHAR(255) PRIMARY KEY,
    checksum CHAR(64) NOT NULL,
    executed_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);

    $applied = $pdo->query(
        'SELECT name, checksum FROM public.schema_migrations'
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    /*
    |------------------------------------------------------------
    | VALIDATE EXISTING MIGRATIONS
    |------------------------------------------------------------
    */

    foreach ($applied as $name => $checksum) {

        if (!isset($migrations[$name])) {
            throw new RuntimeException(
                "Previously applied migration file is missing: {$name}"
            );
        }

        if (
            !hash_equals(
                $checksum,
                $migrations[$name]['checksum']
            )
        ) {
            throw new RuntimeException(
                "Previously applied migration was changed: {$name}"
            );
        }
    }

    /*
    |------------------------------------------------------------
    | EXECUTE NEW MIGRATIONS
    |------------------------------------------------------------
    */

    $insert = $pdo->prepare(
        'INSERT INTO public.schema_migrations
         (name, checksum)
         VALUES (:name, :checksum)'
    );

    $count = 0;

    foreach ($migrations as $name => $migration) {

        if (isset($applied[$name])) {

            echo "[SKIP] {$name}\n";

            continue;
        }

        $callback = require $migration['file'];

        if (!is_callable($callback)) {
            throw new RuntimeException(
                "Migration must return a callable: {$name}"
            );
        }

        $callback($pdo);

        $insert->execute([
            'name' => $name,
            'checksum' => $migration['checksum'],
        ]);

        echo "[APPLIED] {$name}\n";

        $count++;
    }

    /*
    |------------------------------------------------------------
    | COMMIT
    |------------------------------------------------------------
    */

    $pdo->commit();

    echo "[OK] Migration run complete. New migrations: {$count}\n";

} catch (Throwable $error) {

    if (
        $pdo instanceof PDO
        && $pdo->inTransaction()
    ) {
        $pdo->rollBack();
    }

    fwrite(
        STDERR,
        "[ERROR] {$error->getMessage()}\n"
    );

    exit(1);
}